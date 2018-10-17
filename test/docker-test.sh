#!/usr/bin/env bash
DIR=$(dirname $(readlink -f $0))

docker run --rm -it -v ${DIR}:/test hewes/roundcube-utils $@

