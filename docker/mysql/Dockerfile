FROM mysql:8.0

# Durability settings for a database that exists to be dropped. See my.cnf for the
# measurements — the MySQL integration tests were 5–6× slower per test than the
# PostgreSQL ones running the same assertions, and this is most of the difference.
#
# After changing this file, rebuild the container:
#   docker-compose build db && docker-compose up -d db
# `dockertest` checks the settings are in effect and says so if they are not.
COPY my.cnf /etc/mysql/conf.d/zz-test-durability.cnf
