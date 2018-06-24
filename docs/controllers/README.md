# API Controllers
> Documentation is a WIP.


## API Registration

> @todo - complete this

Modules should register by specifying the `nailsapp/module-api.namespace` property in their `composer.json` file. This field is what the API will use to bind URLs to their controllers; e.g. setting this to `store` would expose the module's API controllers at `/api/store/{{controller}`.

The app will always be given the `app` namespace.

Example `composer.json` file (with most fields redacted for berevity):

```json
{
    "extra":
    {
        "nails" :
        {
            "moduleName": "store",
            "type": "module",
            "namespace": "Nails\\Store\\",
            "data": {
                "nailsapp/module-api": {
                    "namespace": "store"
                }
            }
        }
    }
}
```



## Base Controller

All controllers should be placed in the module or App's `src/Api/Controller` directory, with the appropriate Namespace; they should also inherit from the base controller `Nails\Api\Controller\Base`. Methods should return instances of `Nails\Api\Factory\ApiResponse`, setting the payload via the `setData()` method, if required.

Methods are prefixed with the HTTPD method they should bind to.


```php
namespace Nails\Api\App;

use Nails\Api\Controller\Base;
use Nails\Factory;

class MyModel extends Base
{
    public function getIndex()
    {
        $oResponse = Factory::factory('ApiResponse', 'nailsapp/moduke-api');
        $oResponse-setData(['foo' => 'bar']);
        return $oResponse;
    }
}
```


### Authentication

If you need to restrict the endpoint to only users who are logged in then you can set the class' `REQUIRE_AUTH` constant to `true`. If using access tokens, you can specify the scope an access token should have by setting the `REQUIRE_SCOPE` constant.


### Logging

If you need to write to the API log you can do so using the `writeLog($sLine)` method provided by the `Base` class.


### Errors

Any thrown `\Nails\Api\Exception\ApiException` exceptions will be caught by the router and relayed to the user as a typical API response. All other exceptions will not be caught and will be handled by the active ErrorHandler.


## CRUD Controller

> @todo - complete this

The CRUD controller allows you to bind an endpoint to a model and provides typical Create, Read, Update, and Delete functionality.

```php
namespace Nails\Api\App;

use Nails\Api\Controller\CrudController;

class MyModel extends CrudController
{
    const CONFIG_MODEL_NAME     = 'MyModel';
    const CONFIG_MODEL_PROVIDER = 'app';
}
```


## Default Controller

> **THIS CONTROLLER IS DEPRECATED**
> 
> Easily generate Default Controllers using the [Console Command](/docs/console/README.md)

The `DefaultController` is a class which your API endpoints can extend in order to inherit a significant amount of functionality when interfacing with a single model. This allows you to quickly set up an endpoint which provides the following functionality:

- Retrieve by a single ID
- Retrieve by a series of IDs
- Search

On the radar is the ability to create, edit and delete items, too.

To utilize the `DefaultController` simply initialise your class and inherit the `DefaultController` class and specify, at minimum, which model you wish to use. E.g.:

```php
namespace Nails\Api\App;

use Nails\Api\Controller\DefaultController;

class MyModel extends DefaultController
{
    const CONFIG_MODEL_NAME     = 'MyModel';
    const CONFIG_MODEL_PROVIDER = 'app';
}
```

In addition to these two constants, you can also set:

- `CONFIG_MIN_SEARCH_LENGTH` - The minimum length of string for a search; defaults to 0.
- `CONFIG_MAX_ITEMS_PER_REQUEST` - the maximum number of items which can be queried for by ID; defaults to 100.



## URL/Controller Mapping

By default, the URL maps to the controller class on disk, however there may be certain circumstances where you may wish to use a URL which does not map to the named file (e.g. if you wish to use a reserved word).

This is achived by specifying the `controller-map` property in the module's `composer.json` file under the `nailsapp/module-api` namespace:

```json
{
  "extra": {
    "nails" : {
      "data": {
        "nailsapp/module-api": {
          "controller-map": {
            "Object": "MyObject"
          }
        }
      }
    }
  }
}
```

In the above example, the url `api/mymodule/object` would resolve to the controller `MyObject.php`.
