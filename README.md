# adata/laravel-jaeger

Laravel и Lumen обёртка над Jaeger-клиентом для распределённой трассировки.
Форк идеи `chocofamilyme/laravel-jaeger`, адаптированный под **PHP 7.3**.

## Требования

- PHP `^7.3` (совместим с `^8.0`)
- Laravel / Lumen `^8.0`
- `jonahgeorge/jaeger-client-php` `^1.0`
- ext-json
- запущенный jaeger-agent (по умолчанию `jaeger:5775`, Zipkin over Compact UDP)

## Установка

```bash
composer require adata/laravel-jaeger
```

### Laravel

Service provider регистрируется автоматически через `extra.laravel.providers`.
Опубликуйте конфиг:

```bash
php artisan vendor:publish --provider="Adata\LaravelJaeger\LaravelJaegerServiceProvider" --tag=config
```

### Lumen

Добавьте в `bootstrap/app.php`:

```php
$app->configure('jaeger');
$app->register(\Adata\LaravelJaeger\LumenJaegerServiceProvider::class);

// Для HTTP-трейсинга дополнительно:
$app->middleware([\Adata\LaravelJaeger\JaegerMiddleware::class]);
```

Файл `config/jaeger.php` скопируйте вручную из `vendor/adata/laravel-jaeger/config/jaeger.php`.

## Переменные окружения

| Переменная                          | По умолчанию | Назначение                                 |
|-------------------------------------|--------------|--------------------------------------------|
| `JAEGER_SAMPLE_RATE`                | `0.1`        | Доля трейсов от 0 до 1                     |
| `JAEGER_AGENT_HOST`                 | `jaeger`     | Хост jaeger-agent                          |
| `JAEGER_AGENT_PORT`                 | `5775`       | Порт jaeger-agent                          |
| `JAEGER_HTTP_LISTENER_ENABLED`      | `false`      | Автоспан на каждый HTTP-запрос             |
| `JAEGER_CONSOLE_LISTENER_ENABLED`   | `false`      | Автоспан на каждую artisan-команду         |
| `JAEGER_QUERY_LISTENER_ENABLED`     | `false`      | Автоспан на каждый SQL-запрос              |
| `JAEGER_JOB_LISTENER_ENABLED`       | `false`      | Автоспан на каждый выполняемый job         |

## Базовое использование

```php
use Adata\LaravelJaeger\Jaeger;

class OrderService
{
    private $jaeger;

    public function __construct(Jaeger $jaeger)
    {
        $this->jaeger = $jaeger;
    }

    public function place(array $order)
    {
        $this->jaeger->start('order.place', ['order.id' => $order['id']]);

        // ... бизнес-логика ...

        $this->jaeger->stop('order.place', ['order.status' => 'paid']);
    }
}
```

Незакрытые спаны финишируются автоматически при `terminating()`.

### Проброс контекста в другой сервис

```php
$carrier = [];
$this->jaeger->startWithInject('http.call.billing', ['peer' => 'billing'], $carrier);

// carrier теперь содержит uber-trace-id и т.п. — прокидываем в HTTP-заголовки.
$response = $http->request('POST', $url, ['headers' => $carrier]);

$this->jaeger->stop('http.call.billing', ['http.status_code' => $response->getStatusCode()]);
```

### Приём контекста из входящего запроса

`JaegerMiddleware` делает это автоматически. Если инструментируете вручную:

```php
$this->jaeger->initServerContext($_SERVER);
$this->jaeger->start('cli.import');
// ...
$this->jaeger->stop('cli.import');
```

## Автолистенеры

| Слушатель  | События                                                                                          | Класс                                                        |
|------------|--------------------------------------------------------------------------------------------------|--------------------------------------------------------------|
| `http`     | входящие HTTP запросы                                                                            | `Adata\LaravelJaeger\JaegerMiddleware`                       |
| `console`  | `CommandStarting`, `CommandFinished`                                                             | `Adata\LaravelJaeger\Listeners\CommandListener`              |
| `query`    | `QueryExecuted`                                                                                  | `Adata\LaravelJaeger\Listeners\QueryListener`                |
| `job`      | `JobProcessing`, `JobProcessed`, `JobFailed`, `JobExceptionOccurred`                             | `Adata\LaravelJaeger\Listeners\JobListener`                  |

Каждый listener можно переопределить своим классом в `config/jaeger.php` — сигнатуры методов совпадают с встроенными.

## Лицензия

MIT.