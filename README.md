Recipes Checker
===============

The tool used by the CI of [symfony/recipes](https://github.com/symfony/recipes) and
[symfony/recipes-contrib](https://github.com/symfony/recipes-contrib) to validate recipes
and to generate the Flex endpoints.

Checking a recipe locally
-------------------------

Install the tool next to your clone of the recipes repository:

```bash
git clone https://github.com/symfony-tools/recipes-checker.git
composer install --no-dev -d recipes-checker
```

Then run the checks from the root of the recipes repository:

```bash
cd recipes-contrib
../recipes-checker/run lint:manifests --contrib
find * -name '*.yaml' -or -name '*.yml' | ../recipes-checker/run lint:yaml
../recipes-checker/run lint:packages
```

Remove `--contrib` when checking symfony/recipes. `lint:packages` queries packagist.org
for every package of the repository.

Commands
--------

| Command                     | Description
| --------------------------- | ---
| `lint:manifests`            | Checks `manifest.json` files
| `lint:yaml`                 | Validates the YAML files listed on stdin
| `lint:packages`             | Ensures directories map to valid packages on packagist.org
| `lint:pull-request`         | Ensures the pull request can be accepted (CI only)
| `list-unpatched-packages`   | Lists packages that are not patched by the pull request (CI only)
| `diff-recipe-versions`      | Displays the diff between versions of a recipe
| `generate:flex-endpoint`    | Generates the JSON files required by Flex from `git ls-tree HEAD */*/*`
| `generate:archived-recipes` | Generates the history of every recipe
| `generate:create-project-matrix` | Generates the matrix of skeletons and PHP versions that can install the packages listed on stdin
| `generate:recipes-readme`   | Generates the list of all recipes
