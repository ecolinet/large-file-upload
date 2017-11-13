# Large File uploader

The class is a way to make use of PHP's built-in [`enable_post_data_reading`](http://php.net/manual/en/ini.core.php#ini.enable-post-data-reading) setting.

The idea is to allow files upload with a very low memory consumption.


# Installation

Install the latest version with

```
$ composer require ecolinet/large-file
```

# How to ?


## Deactivate `enable_post_data_reading`
First be sure to set `enable_post_data_reading` to `0` on a _specific_ directory (e.g. `public/upload/`).

There are [many ways to do that](http://php.net/manual/en/configuration.changes.php), 
but keep in mind that, since this directive is `PHP_INI_PER_DIR`, it can't be changed
with `ini_set` (it's logical since `_POST` & `_FILES` are processed _before_ the script runs). 

And since it changes in many ways how PHP behaves it is not a good idea to set it globally (e.g. in `php.ini`).


### .htaccess

Here is an example with _apache2_ & _mod_php_ with `.htaccess` overriding enabled :

```
# Root of all things
php_flag enable_post_data_reading 0
```

**Note :** of course it is a [better idea to do that in the main configuration](https://httpd.apache.org/docs/2.4/howto/htaccess.html#when).

### .user.ini

Here is an example if you are using `php-fpm` :

```
; Root of all things
enable_post_data_reading=0
```

**Note :** unlike `.htaccess` files, that kind of files are cached among requests (300s per default), so you can use them without the performance penalty.


## Use the class

Here is a small snippet of how to use it :

```php
$uploader = new \LargeFile\Uploader();
$parts = $uploader->read();
```

`$parts` will be an array of elements composed of :

- `headers` : array of all [`MIME`](https://en.wikipedia.org/wiki/MIME) headers sent by the browser
- `file` : local filename of an uploaded file
- _or_ `content` : content of a posted field

## Typical setup

### Tree-ish view

```
my-app
└── public
    └── upload
        ├── .htaccess
        ├── .user.ini
        └── index.php
```

### `index.php`

```php
<?php
require_once __DIR__ . '/../../vendor/autoload.php';

try {

    // Get uploaded files
    $uploader = new \LargeFile\Uploader();
    $parts = $uploader->read();

} catch (\Exception $e) {

    header('Content-type: application/json; charset=utf-8', true, 500);
    echo json_encode([
        'status'  => "KO",
        'message' => "Error !",
    ]);
    error_log(get_class($e) . ': ' . $e->getMessage());
}

// Persist files out of tmp
foreach ($parts as $part) {
    $filename = $part['headers']['filename'];
    rename(
        $part['file'],
        __DIR__ . '/../../data/upload/' . uniqid() . '-' . $filename
    );
}

// Send success status
header('Content-type: application/json; charset=utf-8', true, 200);
echo json_encode([
    'status'  => "OK",
    'message' => "File(s) uploaded",
]);
```
