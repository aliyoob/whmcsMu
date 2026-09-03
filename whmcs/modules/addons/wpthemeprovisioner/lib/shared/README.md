# Generated shared library

`php bin/build.php` copies `shared/Wtp/Shared/**` and `shared/autoload.php` here.

The same classes are copied into the WordPress agent. Signing, safe extraction,
redaction and the job state machine must behave *identically* on both sides, so
they are written once and distributed, never re-implemented.
