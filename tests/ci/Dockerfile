FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
RUN apt-get update && apt-get install -y --no-install-recommends \
    apache2 ca-certificates composer curl git libapache2-mod-php8.3 \
    php8.3-cli php8.3-curl php8.3-gd php8.3-mbstring php8.3-mysql php8.3-xml php8.3-zip tzdata unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
ARG MATOMO_REF
RUN git init . && git remote add origin https://github.com/matomo-org/matomo.git \
    && git fetch --depth 1 origin "$MATOMO_REF" && git checkout --detach FETCH_HEAD \
    && git submodule update --init --depth 1 --jobs 4 \
    && composer install --prefer-dist --no-progress \
    && cp tests/PHPUnit/phpunit.xml.dist tests/PHPUnit/phpunit.xml \
    && rm -f index.html

COPY bootstrap-timezone.sh /usr/local/bin/bootstrap-timezone
ENTRYPOINT ["bash", "/usr/local/bin/bootstrap-timezone"]
