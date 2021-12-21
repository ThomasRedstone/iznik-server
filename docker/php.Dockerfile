FROM iznik/iznik-base@0.0.1

MAINTAINER "Edward Hibbert" <edward@ehibbert.org.uk>

# PHP
RUN apt-get update \
    && apt-get install -y mysql-client php-xdebug pflogsumm mailutils nginx psmisc \
    build-essential chrpath libssl-dev libxft-dev libfreetype6-dev libfreetype6 libfontconfig1-dev libfontconfig1  \
    monit iotop openssh-client php-dev php-gd gsutil php-redis php-gmp xfsprogs php-soap php-mbstring php-curl \
    php-xml php-mailparse php-mysql php-zip vim net-tools telnet git rsyslog php-pgsql  libdb5.3-dev libxml2-dev \
    libjpeg-dev libpng-dev libxpm4 libfreetype6-dev libgmp3-dev libc-client2007e-dev libldap2-dev libmcrypt-dev \
	libmhash-dev freetds-dev zlib1g-dev libmysqlclient-dev libncurses5-dev libpcre3-dev unixodbc-dev libsqlite0-dev libaspell-dev \
	libreadline6-dev librecode-dev libsnmp-dev libtidy-dev libxslt1-dev libssl-dev libxml2 libxml2-dev libpspell-dev libicu-dev \
	sysstat libwww-perl nasm
RUN apt-get remove -y apache2* sendmail* mlocate php-ssh2

# GeoIP
#
RUN	apt-get install -y php-pear \
# Can no longer install GeoLite2-Country.mmdb as not exposed without account/key.
    && apt-get install -y php-dev libgeoip-dev libcurl4-openssl-dev wget golang-go \
    && pecl install geoip-1.1.1

# Mailparse
RUN apt-get install -y php-mbstring php-mailparse 

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
CMD /etc/init.d/nginx start
  # Set up the environment we need for running our UT.  Putting this here means it gets reset each
  # time we start the container.
	#
	# We need to make some minor schema tweaks otherwise the schema fails to install.
    && php install/testenv.php \
    # Keep the container alive
	&& bash
	
#
#--------------------------------------------------------------------------
# Init
#--------------------------------------------------------------------------
#

EXPOSE 80
