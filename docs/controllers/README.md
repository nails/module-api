# API Controllers
> Documentation is a WIP.


## Base Controller

All controllers should inherit from the base controller `Nails\Api\Controller\Base`.


### Authentication

If you need to restrict the endpoint to only users who are logged in then you can set the class' `REQUIRE_AUTH` const to `true`.


### Logging

If you need to write to the API log you can do so using the `writeLog($sLine)` method provided by the `Base` class.


### 404

If for whatever reason you need to simulate a 404, then do so using the `methodNotFound($method)` method provided by the `Base` class.


## Default Controller

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
