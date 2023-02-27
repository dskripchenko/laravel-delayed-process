## Installation

```
$ php composer.phar require dskripchenko/laravel-delayed-process
```

or add

```
"dskripchenko/laravel-delayed-process": "^1.0"
```

to the ```require``` section of your `composer.json` file.

## Example

### Create Process
```php
//Handler.php
class Handler
{
    public function handle(int $a, int $b, int $sleep)
    {
        sleep($sleep);
        $c = $a + $b;
        return "{$a} + {$b} = {$c}";
    }  
}

//.....
use Dskripchenko\DelayedProcess\Models\DelayedProcess;
use Handler;

Route::get('/api/create-delayed-process/', function () {
    $process = DelayedProcess::make(
        Handler::class,
        'handle',
        1,
        2,
        100
    );

    return [
        'payload' => $process->toResponse()
    ];
});


```

### Read Process
```php
use Illuminate\Support\Facades\Route;
use Dskripchenko\DelayedProcess\Models\DelayedProcess;

Route::get('/api/common/delayed-process/status/{uuid}', function ($uuid) {
    /** @var DelayedProcess $process */
    $process = DelayedProcess::query()
        ->where('uuid', $uuid)
        ->firstOrFail();
        
    return [
        'status' => $process->status,
        'data' => $process->data,
    ];
})->name('status-delayed-process');

```

### Axios 
```js
import axios, {AxiosInstance} from 'axios'
import https from 'https'

const httpsAgent = new https.Agent({
  rejectUnauthorized: false,
})

const customAxios: AxiosInstance = axios.create({
  baseURL: process.env.API_URL || '',
  withCredentials: true,
  httpsAgent,
})

customAxios.interceptors.response.use((response) => {
  const originalResponse = response;
  const data = response.data;
  const uuid = data?.payload?.delayed?.uuid;

  if (!uuid) {
    return response;
  }

  const timeout = 3000;
  let intervalId = null;
  let isRunning = false

  return new Promise((resolve, reject) => {

    const handler = () => {
      isRunning = true;
      customAxios.get('/api/common/delayed-process/status/' + uuid )
        .then((delayedResponse) => {
          isRunning = false;
          const delayedData = delayedResponse?.data?.payload;
          const status = delayedData.status;
          const result = delayedData.data;

          if (status === 'done') {
            if (intervalId) {
              clearInterval(intervalId);
            }
            originalResponse.data.payload = result;
            return resolve(originalResponse);
          }
          else if (status === 'error') {
            if (intervalId) {
              clearInterval(intervalId);
            }
            originalResponse.data.payload = result;
            return reject(originalResponse);
          }

        })
        .catch((error) => {
          isRunning = false;
          if (intervalId) {
            clearTimeout(intervalId);
          }
          reject(error);
        });
    };

    intervalId = setInterval(() => {
      if (isRunning) {
        return;
      }
      handler();
    }, timeout);

  });

}, async function (error) {
  return Promise.reject(error);
});

export const HTTP_REST_SERVER = customAxios

```

## Usage
```js
HTTP_REST_SERVER.get('/api/create-delayed-process')
    .then(console.log);

//... after 100 seconds ...  {data:{payload:['1 + 2 = 3']}} 

```