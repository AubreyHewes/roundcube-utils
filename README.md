[![Docker Pulls](https://img.shields.io/docker/pulls/hewes/roundcube-utils.svg)](https://hub.docker.com/r/hewes/roundcube-utils/)

# Misc roundcube utilities

Some utilities for [roundcube](https://roundcube.net)

## Commands

|Command|Description|
|---|---|
|db:copy| Copy a database to another database|

# Run in docker

Get the image

    docker pull hewes/roundcube-utils

Then run a command

    docker run --rm --it hewes/roundcube-utils -v SOME_SQLITE_DB:/db.sqlite db:copy --from-uri sqlite:/db.sqlite --to-uri ${DATBASE_URL}

# TODO

 * [ ] tests :sheep:
