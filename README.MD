Install
-------
**Step 1: Run**
```bash
composer req yauhenko/rest-bundle
```

**Step 2: Add to `config/routes/annotations.yaml` two lines:**
```yaml
rest_bundle:
    resource: '@RestBundle/config/routes.yaml'
```
It will register `/docs` routes

**Step 3: Create Types class in `src/Types.php`:**
```php
<?php

namespace App;

use Yauhenko\RestBundle\TypesInterface;
use Yauhenko\RestBundle\Service\TypeScript;

class Types implements TypesInterface {

    public static function registerTypes(TypeScript $ts): void {
        // register custom types here (optional)
        $ts->registerTypeOf('TBadge', ['notifications', 'shop']);
        
        // register interfaces
        $ts->registerInterfacesFromDir(__DIR__ . '/Entity');
        $ts->registerInterfacesFromDir(__DIR__ . '/Models');
    }
    
    public static function codePostProcessor(string $code): string {
        // change generated TypeScript code here (optional)	
        return $code;
    }

}
```
You can change path to Types class using `yauhenko.rest.types_class` parameter in `config/services.yaml`

*Step 4 (optional): Configure `config/services.yaml`:*
```yaml
parameters:
    # Enable TypeScript generation (default: true)
    yauhenko.rest.ts_enabled: '%env(bool:API_TS_ENABLED)%'
    
    # Types class (default) 
    yauhenko.rest.types_class: 'App\Types'
    
    # Path to controllers (default) 
    yauhenko.rest.controllers_dir: '%kernel.project_dir%/src/Controller'
```
