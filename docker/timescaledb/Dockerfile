# The framework's TimescaleDB, with mysql_fdw available for `db:migrate-from-mysql --transport=fdw`.
#
# The same pinned image the suite has always run on (see docker-compose.yml for why 2.26.4),
# plus one extension, built from source: it is not packaged for Alpine. It is only made
# available; nothing creates it unless a test or the command asks for it.
FROM timescale/timescaledb:2.26.4-pg17

ARG MYSQL_FDW_VERSION=REL-2_9_2

# The test MySQL's certificate is self-signed, and MariaDB's connector verifies by default. For
# this image only — the framework's tests — not for a server that moves real data.
ENV MARIADB_TLS_DISABLE_PEER_VERIFICATION=1

RUN set -eux; \
    apk add --no-cache mariadb-connector-c; \
    apk add --no-cache --virtual .build git make gcc musl-dev mariadb-connector-c-dev; \
    git clone --depth 1 --branch "$MYSQL_FDW_VERSION" https://github.com/EnterpriseDB/mysql_fdw.git /tmp/mysql_fdw; \
    cd /tmp/mysql_fdw; \
    # RTLD_DEEPBIND is glibc's; Alpine's musl has no such flag, and none is needed there.
    sed -i 's/RTLD_DEEPBIND/0/' mysql_fdw.c; \
    # No LLVM bitcode: the JIT is not what this extension is for, and the toolchain is not here.
    make USE_PGXS=1 with_llvm=no; \
    make USE_PGXS=1 with_llvm=no install; \
    cd /; rm -rf /tmp/mysql_fdw; \
    apk del .build; \
    # mysql_fdw loads the client library by its MySQL name at run time; MariaDB's connector is it.
    # After the build packages go: the -dev package owns a link of that name and takes it along.
    ln -sf "$(ls /usr/lib/libmariadb.so.* | head -1)" /usr/lib/libmysqlclient.so
