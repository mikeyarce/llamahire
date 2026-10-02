FROM wordpress:php7.4

# The legacy PHP image uses Bullseye package indexes that reference removed
# security updates. Use the signed Debian archive for this isolated test image.
RUN printf 'deb https://archive.debian.org/debian bullseye main\n' > /etc/apt/sources.list \
    && rm -f /etc/apt/sources.list.d/* \
    && apt-get -o Acquire::Check-Valid-Until=false update \
    && apt-get -y install $PHPIZE_DEPS
