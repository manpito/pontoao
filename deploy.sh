#!/bin/bash
cd /var/www/saas
git pull origin main
chown -R www-data:www-data /var/www/saas
find /var/www/saas -type d -not -path '*/.git/*' -exec chmod 755 {} \;
find /var/www/saas -type f -not -path '*/.git/*' -name '*.sh' -exec chmod 755 {} \;
chmod 640 /var/www/saas/.env
echo "Deploy concluído: $(date)"
