FROM ubuntu:20.04

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

# 3rd party components
RUN apt-get update \
	&& apt-get install -y mysql-server \
	&& rm -rf /var/lib/mysql \
	&& mkdir /var/lib/mysql \
	&& apt-get install -y redis-server \
	&& apt-get install -y beanstalkd 

# SSHD
RUN apt-get -y install openssh-server \
	&& mkdir /var/run/sshd \
	&& echo 'root:password' | chpasswd \
	&& sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config \
	&& sed 's@session\s*required\s*pam_loginuid.so@session optional pam_loginuid.so@g' -i /etc/pam.d/sshd \
	&& echo "export VISIBLE=now" >> /etc/profile
		
# SpamAssassin
RUN apt-get install -y spamassassin spamc psmisc \
	&& groupadd spamd \
    && useradd -g spamd -s /bin/false -d /var/log/spamassassin spamd \
    && mkdir /var/log/spamassassin \
    && sed -i 's/ENABLED=0/ENABLED=1/g' /etc/default/spamassassin \
    && sed -i 's/home-dir/home-dir -4/g' /etc/default/spamassassin 

# More packages
RUN apt-get install -y autoconf automake libtool re2c flex make libxml2-dev libssl-dev libbz2-dev libcurl4-openssl-dev \
	libdb5.3-dev libjpeg-dev libpng-dev libxpm4 libfreetype6-dev libgmp3-dev libc-client2007e-dev libldap2-dev libmcrypt-dev \
	libmhash-dev freetds-dev zlib1g-dev libmysqlclient-dev libncurses5-dev libpcre3-dev unixodbc-dev libsqlite0-dev libaspell-dev \
	libreadline6-dev librecode-dev libsnmp-dev libtidy-dev libxslt1-dev libssl-dev libxml2 libxml2-dev libpspell-dev libicu-dev \
	sysstat libwww-perl nasm php-zip unzip mysql-client php-mysql php-xdebug pflogsumm mailutils nginx psmisc \
	build-essential chrpath libssl-dev libxft-dev libfreetype6-dev libfreetype6 libfontconfig1-dev libfontconfig1  \
	monit iotop openssh-client php-dev php-gd gsutil php-redis php-gmp xfsprogs php-soap php-mbstring php-curl \
	php-xml php-mailparse php-mysql php-zip vim net-tools telnet git rsyslog php-pgsql
RUN apt-get remove -y apache2* sendmail* mlocate php-ssh2

# Postgres
RUN apt-get install postgresql postgis postgresql-12-postgis-3
RUN sed -i  '/^local.*all.*all.*peer/ s/peer/md5/' /etc/postgresql/12/main/pg_hba.conf
RUN /etc/init.d/postgresql start
RUN su -c "psql -c \"CREATE USER iznik WITH PASSWORD 'iznik'\";" postgres
RUN su -c "psql -c \"ALTER ROLE iznik superuser;\"" postgres
RUN su -c "psql -c \"CREATE DATABASE iznik;\"" postgres

# GeoIP
#
RUN	apt-get install -y php-pear \
# Can no longer install GeoLite2-Country.mmdb as not exposed without account/key.
    && apt-get install -y php-dev libgeoip-dev libcurl4-openssl-dev wget golang-go \
    && pecl install geoip-1.1.1

# Mailparse
RUN apt-get install -y php-mbstring php-mailparse 

#
#--------------------------------------------------------------------------
# Iznik
#--------------------------------------------------------------------------
#
RUN mkdir -p /var/www \
	&& cd /var/www \
	&& apt-get update \
	&& git clone https://github.com/Freegle/iznik-server.git iznik \
	&& cp iznik/install/mysqldamlevlim.so /usr/lib/mysql/plugin/ \
  && touch iznik/standalone

WORKDIR /var/www/iznik

# /etc/iznik.conf is where our config goes on the live server.  We have some keys in environment variables.
RUN cp install/iznik.conf.php /etc/iznik.conf \
    && sed -ie "s/'GOOGLE_VISION_KEY', 'zzz'/'GOOGLE_VISION_KEY', ' \GOOGLE_VISION_KEY'/g" /etc/iznik.conf \
    && sed -ie "s/'TWITTER_CONSUMER_KEY', 'zzzz'/'TWITTER_CONSUMER_KEY', ' \TWITTER_CONSUMER_KEY'/g" /etc/iznik.conf \
    && sed -ie "s/'TWITTER_CONSUMER_SECRET', 'zzzz'/'TWITTER_CONSUMER_SECRET', ' \TWITTER_CONSUMER_SECRET'/g" /etc/iznik.conf \
    && sed -ie "s/'AZURE_CONNECTION_STRING', 'zzzz'/'AZURE_CONNECTION_STRING', ' \AZURE_CONNECTION_STRING'/g" /etc/iznik.conf \
    && sed -ie "s/'PLAYGROUND_TOKEN', 'zzzz'/'PLAYGROUND_TOKEN', ' \PLAYGROUND_TOKEN'/g" /etc/iznik.conf \
    && sed -ie "s/'PLAYGROUND_SECRET', 'zzzz'/'PLAYGROUND_SECRET', ' \PLAYGROUND_SECRET'/g" /etc/iznik.conf \
    # phpunit.xml is set up for running tests on our debug server.
    && sed -ie 's/\/var\/www\/iznik.mt.dbg\//\//g' test/ut/php/phpunit.xml 

# Install composer dependencies
RUN apt-get install wget \
    && wget https://getcomposer.org/composer-1.phar \
    && mv composer-1.phar composer.phar \
    && cd composer \
    && php ../composer.phar install \
    && cd ..

# Redis.  Won't start listening on ::1.
RUN	sed -ie 's/::1//g' /etc/redis/redis.conf \
	&& sed -ie 's/\/var\/run\/redis\/redis.sock/127.0.0.1/g' include/config.php

# Cron jobs for background scripts
RUN cat install/crontab | crontab -u root -

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
	&& /etc/init.d/redis-server start \
	&& /etc/init.d/beanstalkd start \
	&& /etc/init.d/nginx start \
	&& /etc/init.d/ssh start \
	&& /etc/init.d/spamassassin start \
	&& /etc/init.d/cron start \
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
    && php install/testenv.php \
    # Keep the container alive
	&& bash
	
#
#--------------------------------------------------------------------------
# Init
#--------------------------------------------------------------------------
#

EXPOSE 22 80 443 
