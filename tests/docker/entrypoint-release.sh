#!/bin/sh

# Remove unused files
rm -f -r tests/
rm -f -r var/cache/*
rm -f -r var/sessions/*
rm -f -r var/log/*
rm -f bin/phpunit
rm -f .dockerignore .env.test .gitignore behat.yml composer.* phpunit.xml symfony.lock publiccode.yml
rm -f -r ./*/*/.gitkeep ./*/*/*/.gitkeep

# Rename .env to avoid overwriting on update
mv .env .env-dist

# Archive release
zip -q -y -r giuaschool-release.zip ./
