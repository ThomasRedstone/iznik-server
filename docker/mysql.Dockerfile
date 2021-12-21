FROM iznik/iznikBase@0.0.1

MAINTAINER "Edward Hibbert" <edward@ehibbert.org.uk>

ENV MYSQL_USER=mysql \
    MYSQL_VERSION=5.7.22 \
    MYSQL_DATA_DIR=/var/lib/mysql \
    MYSQL_RUN_DIR=/run/mysqld \
    MYSQL_LOG_DIR=/var/log/mysql \
	  DEBIAN_FRONTEND=noninteractive \
	  TZ='UTZ' \
	  NOTVISIBLE="in users profile" \
	  STANDALONE=TRUE

# MySQL
RUN apt-get install -y mysql-server \
	&& rm -rf /var/lib/mysql \
	&& mkdir /var/lib/mysql

# Postgres
RUN apt-get install postgresql postgis postgresql-12-postgis-3
RUN sed -i  '/^local.*all.*all.*peer/ s/peer/md5/' /etc/postgresql/12/main/pg_hba.conf
RUN /etc/init.d/postgresql start
RUN su -c "psql -c \"CREATE USER iznik WITH PASSWORD 'iznik'\";" postgres
RUN su -c "psql -c \"ALTER ROLE iznik superuser;\"" postgres
RUN su -c "psql -c \"CREATE DATABASE iznik;\"" postgres

# Tidy image
RUN rm -rf /var/lib/apt/lists/*

# What happens when we start the container
# First start some services.  MySQL is a bit of a faff.
#
# This isn't very Docker, as each container should really only have one command.  I don't want to require docker-compose
# as that's extra faff for the user.  If you are reading this and know why this is obviously stupid, perhaps you
# could fix it.
CMD rm -rf /var/lib/mysql/* \
  && rm -f /var/log/mysql/error.log \
	&& usermod -d /var/lib/mysql/ mysql \
	&& mkdir -p /var/lib/mysql \
	&& chown -R mysql:mysql /var/lib/mysql \
	&& mysqld --initialize-insecure \
	&& echo [server] >> /etc/mysql/my.cnf \
	&& echo max_allowed_packet=32MB >> /etc/mysql/my.cnf \
	&& /etc/init.d/mysql start \
	&& mysql -u root -e 'CREATE DATABASE IF NOT EXISTS iznik;' \
  # Set up the environment we need for running our UT.  Putting this here means it gets reset each
  # time we start the container.
	#
	# We need to make some minor schema tweaks otherwise the schema fails to install.
    && sed -ie 's/ROW_FORMAT=DYNAMIC//g' install/schema.sql \
    && sed -ie 's/timestamp(3)/timestamp/g' install/schema.sql \
    && sed -ie 's/timestamp(6)/timestamp/g' install/schema.sql \
    && sed -ie 's/CURRENT_TIMESTAMP(3)/CURRENT_TIMESTAMP/g' install/schema.sql \
    && sed -ie 's/CURRENT_TIMESTAMP(6)/CURRENT_TIMESTAMP/g' install/schema.sql \
	&& mysql -u root -e 'CREATE DATABASE IF NOT EXISTS iznik;' \
    && mysql -u root iznik < install/schema.sql \
    && mysql -u root iznik < install/functions.sql \
    && mysql -u root -e "SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'" \
    && mysql -u root -e "CREATE FUNCTION damlevlim RETURNS INT SONAME 'mysqldamlevlim.so'" \
    # Keep the container alive
	&& bash
	
#
#--------------------------------------------------------------------------
# Init
#--------------------------------------------------------------------------
#

EXPOSE 22 80 443 
