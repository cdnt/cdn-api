# TinyCDN API

This package work with GraphQL API of [TinyCDN Service](https://tinycdn.cloud) 

More info you can find on [TinyCDN Service](https://tinycdn.cloud/docs/)

## Install

```
composer require "tinycdn/cdn-api"
```

## Usage

```php
<?php

$cdn = new \Tinycdn\CdnApi('your-api-token');

try {
    
    // simple upload
    $data1 = $cdn->upload('path/to/file.ext');
    var_dump($data1);

    // more options
    $params = [
        'is_public' => false,        // default true
        'type'      => 'image',      // default file
        'filename'  => 'my-img.jpg', // if not set, filename will be detected automatically
        'rule_key'  => 'my-rule',    // see docs for details
    ];
    $data2 = $cdn->upload('https://images.local/image.jpg', $params);
    var_dump($data2);


} catch (Exception $e) {
    echo $e->getMessage();
}

```
