Install
-------
Step 1: Run
```bash
composer req yauhenko/rest-bundle
```

Step 2: Add to `config/routes/annotations.yaml` two lines:
```yaml
rest_bundle:
    resource: '@RestBundle/config/routes.yaml'
```

Step 3 (optional): Configure in `config/services.yaml`:
```yaml
parameters:
    
    # Page title (default: 'Rest API Docs') 
    yauhenko.rest.title: 'My API Docs'
    
    # Logo (default: '')
    yauhenko.rest.logo: '/assets/images/logo.png'
    
    # Enable docs route /docs (default: true)
    yauhenko.rest.docs_enabled: '%env(bool:API_DOCS_ENABLED)%'
    
    # Enable TypeScript generation (default: true)
    yauhenko.rest.ts_enabled: '%env(bool:API_TS_ENABLED)%'
    
    # Types class (default) 
    yauhenko.rest.types_class: 'App\Types'
    
    # Path to cache dir (default) 
    yauhenko.rest.cache_dir: '%kernel.cache_dir%'
    
    # Root project path (default) 
    yauhenko.rest.project_dir: '%kernel.project_dir%'
    
    # Path to controllers (default) 
    yauhenko.rest.controllers_dir: '%kernel.project_dir%/src/Controller'
```
