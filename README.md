# 

[![Latest Version on Packagist](https://img.shields.io/packagist/v/gabrielesbaiz/duplicate-toolkit.svg?style=flat-square)](https://packagist.org/packages/gabrielesbaiz/duplicate-toolkit)
[![Total Downloads](https://img.shields.io/packagist/dt/gabrielesbaiz/duplicate-toolkit.svg?style=flat-square)](https://packagist.org/packages/gabrielesbaiz/duplicate-toolkit)

A lightweight helper package to handle Eloquent model duplication.

Original code from [neurony/laravel-duplicate](https://github.com/neurony/laravel-duplicate)

## Features

- ✅ Duplicate any Eloquent model record along with its underlying relationships.

## Installation

You can install the package via composer:

```bash
composer require gabrielesbaiz/duplicate-toolkit
```

## Usage

### Step 1

Your Eloquent models should use the `Gabrielesbaiz\DuplicateToolkit\Traits\HasDuplicates` trait and the `Gabrielesbaiz\DuplicateToolkit\Options\DuplicateOptions` class.   

The trait contains an abstract method `getDuplicateOptions()` that you must implement yourself.   

Example:

```php
<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Gabrielesbaiz\DuplicateToolkit\Options\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\Traits\HasDuplicates;

class YourModel extends Model
{
    use HasDuplicates;
    
    /**
     * Get the options for duplicating the model.
     *
     * @return DuplicateOptions
     */
    public function getDuplicateOptions(): DuplicateOptions
    {
        return DuplicateOptions::instance();
    }
}
```

### Step 2

Once you've used the `Gabrielesbaiz\DuplicateToolkit\Traits\HasDuplicates` trait in your Eloquent models, you can duplicate model records by using the `saveAsDuplicate()` method present on that trait.

```php
$model = YourModel::find($id);

$duplicatedModel = $model->saveAsDuplicate(); // returns the newly duplicated model instance
```

## Customisations

### Exclude certain columns

When duplicating a model, you can exclude certain columns from being duplicated by using the `excludeColumns()` method in your definition of the `getDuplicateOptions()` method.   
   
The fields specified in the `excludeColumns()` method will be saved with their default value (`null`, `false`, `0`, etc.)

```php
/**
 * Get the options for duplicating the model.
 *
 * @return DuplicateOptions
 */
public function getDuplicateOptions() : DuplicateOptions
{
    return DuplicateOptions::instance()
        ->excludeColumns('column_one', 'column_two');
}
```

### Specify unique columns

When duplicating a model, you can save certain columns in an unique format by using the `uniqueColumns()` method in your definition of the `getDuplicateOptions()` method.   
   
The fields specified in the `uniqueColumns()` method will be saved in a unique format by appending `(n)` at the end.   
Example: **original name (1)**, **original name (2)**

```php
/**
 * Get the options for duplicating the model.
 *
 * @return DuplicateOptions
 */
public function getDuplicateOptions() : DuplicateOptions
{
    return DuplicateOptions::instance()
        ->uniqueColumns('column_one', 'column_two');
}
```

### Exclude entire relations

By default, when duplicating a model, all of its "child" relations are also duplicated along with it.   
   
You can exclude certain relations from being duplicated by using the `excludeRelations()` method in your definition of the `getDuplicateOptions()` method.   
   
The relations specified in the `excludeRelations()` method will not be duplicated along with the targeted model, meaning that the newly duplicated model will not have any records associated to it for the specified relations.

```php
/**
 * Get the options for duplicating the model.
 *
 * @return DuplicateOptions
 */
public function getDuplicateOptions() : DuplicateOptions
{
    return DuplicateOptions::instance()
        ->excludeRelations('relationOne', 'relationTwo');
}
```

### Exclude certain columns from certain relations

When duplicating a model, you can exclude certain columns of its "child" relations from being duplicated by using the `excludeRelationColumns()` method in your definition of the `getDuplicateOptions()` method.   
   
> This method accepts only one parameter which should be an associative array containing:   
>   **key** -> the name of a relation   
>   **value** -> an array containing the columns to exclude for that relation
   
The fields specified in the `excludeRelationColumns()` method will be saved with their default value (`null`, `false`, `0`, etc.)

```php
/**
 * Get the options for duplicating the model.
 *
 * @return DuplicateOptions
 */
public function getDuplicateOptions() : DuplicateOptions
{
    return DuplicateOptions::instance()
        ->excludeRelationColumns([
            'relationOne' => ['column_one', 'column_two'],
            'relationTwo' => ['column_one'],
        ]);
}
```

### Specify unique columns for certain relations

When duplicating a model, you can save certain columns of its "child" relations in an unique format by using the `uniqueRelationColumns()` method in your definition of the `getDuplicateOptions()` method.   
   
> This method accepts only one parameter which should be an associative array containing:   
>   **key** -> the name of a relation   
>   **value** -> an array containing the unique columns for that relation   
   
The fields specified in the `uniqueRelationColumns()` method will be saved in an unique format by appending `(n)` at the end.   
Example: **original relation name (1)**, **original relation name (2)**

```php
/**
 * Get the options for duplicating the model.
 *
 * @return DuplicateOptions
 */
public function getDuplicateOptions() : DuplicateOptions
{
    return DuplicateOptions::instance()
        ->uniqueRelationColumns([
            'relationOne' => ['column_one', 'column_two'],
            'relationTwo' => ['column_one'],
        ]);
}
```

### Duplicate only the targeted model

If you only want to duplicate your targeted model without duplicating any relations whatsoever, you can specify this by using the `disableDeepDuplication()` method in your definition of the `getDuplicateOptions()` method.   
   
When using this method, all relations of all types will be ignored when duplicating the model.

```php
/**
 * Get the options for duplicating the model.
 *
 * @return DuplicateOptions
 */
public function getDuplicateOptions() : DuplicateOptions
{
    return DuplicateOptions::instance()
        ->disableDeepDuplication();
}
```

## Events

The duplicate functionality comes packed with two Eloquent events: `duplicating` and `duplicated`   
   
You can implement these events in your Eloquent models as you would implement any other Eloquent events that come with the Laravel framework.

```php
<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Gabrielesbaiz\DuplicateToolkit\Options\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\Traits\HasDuplicates;

class YourModel extends Model
{
    use HasDuplicates;

    /**
     * Boot the model.
     *
     * @return DuplicateOptions
     */
    public static function boot()
    {
        parent::boot();

        static::duplicating(function ($model) {
            // your logic here
        });

        static::duplicated(function ($model) {
            // your logic here
        });
    }
    
    /**
     * Get the options for duplicating the model.
     *
     * @return DuplicateOptions
     */
    public function getDuplicateOptions(): DuplicateOptions
    {
        return DuplicateOptions::instance();
    }
}
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Neurony Solutions](https://github.com/neurony)
- [Gabriele Sbaiz](https://github.com/gabrielesbaiz)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
