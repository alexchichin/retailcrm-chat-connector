# chat-connector

Symfony-демон для интеграции MG Bot (RetailCRM MessageGateway) с RetailCRM.

При получении команды `/start <base64(email)>` в чате находит клиента в RetailCRM по email и привязывает его последний заказ к диалогу. Если клиент не найден — повторяет попытку каждые 30 минут, до 10 раз. Задачи хранятся в MySQL через Symfony Messenger, поэтому перезапуск процесса не теряет очередь.

## Требования

- PHP 8.4+
- MySQL 8.0+
- Composer

## Установка

```bash
git clone <repo-url> chat-connector
cd chat-connector
composer install
```

## Настройка

Создай файл `.env.local` на основе `.env`:

```bash
cp .env .env.local
```

Заполни переменные:

```dotenv
APP_SECRET=<случайная строка>

DATABASE_URL="mysql://user:password@127.0.0.1:3306/chat_connector?serverVersion=8.0"
MESSENGER_TRANSPORT_DSN="doctrine://default?auto_setup=1"

# MG Bot — токен и URL выдаются при регистрации бота через RetailCRM API
# POST /api/v5/integration-modules/{code}/edit → info.mgBot.token + info.mgBot.endpointUrl
MG_WS_URL="wss://mg-s1.retailcrm.pro/api/bot/v1/ws"
MG_WS_TOKEN="<токен из info.mgBot.token>"

# RetailCRM
RETAILCRM_URL="https://<аккаунт>.retailcrm.ru"
RETAILCRM_API_KEY="<api-ключ>"
RETAILCRM_SITE="<символьный код магазина>"
```

## База данных

```bash
# Создать БД
mysql -u root -p -e "CREATE DATABASE chat_connector CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Создать таблицу очереди
bin/console messenger:setup-transports
```

## Запуск

Два процесса должны работать одновременно:

```bash
# WS-демон — слушает MG Bot и кладёт задачи в очередь
bin/console app:mg:listen

# Consumer — обрабатывает задачи из очереди
bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
```

## Продакшн (Supervisor)

Установи Supervisor и создай конфиг `/etc/supervisor/conf.d/chat-connector.conf`:

```ini
[program:chat-connector-listen]
command=php /var/www/chat-connector/bin/console app:mg:listen
directory=/var/www/chat-connector
autostart=true
autorestart=true
startretries=5
stderr_logfile=/var/log/supervisor/chat-connector-listen.err.log
stdout_logfile=/var/log/supervisor/chat-connector-listen.out.log
user=www-data

[program:chat-connector-consumer]
command=php /var/www/chat-connector/bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
directory=/var/www/chat-connector
autostart=true
autorestart=true
startretries=5
stderr_logfile=/var/log/supervisor/chat-connector-consumer.err.log
stdout_logfile=/var/log/supervisor/chat-connector-consumer.out.log
user=www-data
```

Применить:

```bash
supervisorctl reread && supervisorctl update
supervisorctl start chat-connector-listen chat-connector-consumer
```

MySQL автозапуск:

```bash
systemctl enable mysql
```

## Тесты

```bash
composer install
vendor/bin/phpunit
```

Тестовое окружение настраивается через `.env.test`.

## Архитектура

```
MG Bot WebSocket
      |
      | /start <base64(email)>
      v
MgBotListenCommand          — WS-демон, декодирует email, диспатчит сообщение
      |
      | dispatch(BindDialogMessage)
      v
messenger_messages (MySQL)  — очередь задач
      |
      v
BindDialogMessageHandler    — ищет клиента, берёт последний заказ, привязывает диалог
      |
      | если клиент не найден
      v
re-dispatch + DelayStamp(30 min) — повтор до 10 раз
```

### Ключевые классы

| Класс | Описание |
|---|---|
| `MgBotListenCommand` | WS-демон, точка входа |
| `RetailCrmService` | HTTP-клиент к RetailCRM API |
| `BindDialogMessage` | DTO сообщения очереди |
| `BindDialogMessageHandler` | Обработчик с retry-логикой |
