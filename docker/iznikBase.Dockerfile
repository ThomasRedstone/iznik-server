FROM iznik/base@0.0.1

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
