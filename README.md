Unit test generator
=====================

Proof-of-concept component providing unit test generator.

## Documentation
This project can generate or single unit for one class or for all project with all needed mocks.

## Getting started
* For example if you want generate unit test for one class use following code:

  `$testGenerator = new MicroModule\UnitTestGenerator\Service\TestClass();`  
  `$testGenerator->generate(FooService::class);`

* For generate tests and mocks for all project use:
    
    `$testGenerator = new MicroModule\UnitTestGenerator\Service\TestProject(realpath('src'), ['Migrations', 'Presentation', 'Exception']);`
    `$testGenerator->generate();`

    second argument is array of excluded folders
    
## License
This project is licensed under the MIT License - see the LICENSE file for details
