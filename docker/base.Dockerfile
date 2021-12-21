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
    && apt-get install -y autoconf automake libtool re2c flex make libssl-dev libbz2-dev libcurl4-openssl-dev unzip

# SSHD
RUN apt-get -y install openssh-server \
	&& mkdir /var/run/sshd \
	&& echo 'root:password' | chpasswd \
	&& sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config \
	&& sed 's@session\s*required\s*pam_loginuid.so@session optional pam_loginuid.so@g' -i /etc/pam.d/sshd \
	&& echo "export VISIBLE=now" >> /etc/profile

# Tidy image
RUN rm -rf /var/lib/apt/lists/*