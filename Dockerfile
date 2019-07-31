FROM mydnshost/mydnshost-docker-cron:latest

RUN mkdir /output

COPY crontab /etc/cron.d/maintenance

COPY scripts /scripts
