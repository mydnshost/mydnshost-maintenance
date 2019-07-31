FROM mydnshost/mydnshost-docker-cron:latest

RUN mkdir /output

COPY crontab /etc/cron.d/maintenance

COPY scripts /scripts

RUN apt-get clean && apt-get update && apt-get install unzip && rm -rf /var/lib/apt/lists/* && \
    cd /scripts/statuscake-updater && composer install && \
    cd /scripts/gather-statistics && composer install
