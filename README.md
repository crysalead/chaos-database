# Chaos Database Driver

[![Build Status](https://travis-ci.org/crysalead/chaos-database.png?branch=master)](https://travis-ci.org/crysalead/chaos-database)
[![Scrutinizer Coverage Status](https://scrutinizer-ci.com/g/crysalead/chaos-database/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/crysalead/chaos-database/?branch=master)

PDO database drivers for [Chaos](https://github.com/crysalead/chaos).

## Community

To ask questions, provide feedback or otherwise communicate with the team, join us on `#chaos` on Freenode.

## Documentation

See the whole [documentation here](http://chaos.readthedocs.org/en/latest).

## Requirements

 * PHP 5.5+

### Testing

Updates `kahlan-config.php` to set some valid databases configuration (or remove them) to run only unit tests then run the specs with:

```
cd chaos-database
composer install
./bin/kahlan
```
