# Upstream baseline

Current customization baseline: **phpIPAM 1.8.2**.

Upstream repository: `phpipam/phpipam`.

Until the runtime snapshot/import task is complete, do not assume every file in the live `/var/www/html` tree is represented here.

The intended normalization workflow is:

1. capture only known customized source/configuration-free files from live runtime;
2. obtain pristine phpIPAM 1.8.2 versions of the same paths;
3. generate an exact diff;
4. review and remove superseded experimental code;
5. commit consolidated source changes;
6. validate clean installation on a disposable copy.

No production secrets, `config.php` credentials, session files, uploads or database content belong in this repository.
