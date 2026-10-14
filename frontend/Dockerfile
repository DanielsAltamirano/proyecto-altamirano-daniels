FROM nginx:1.27-alpine

# Copiar la configuración personalizada del proxy
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Copiar los archivos estáticos de la aplicación
COPY src/ /usr/share/nginx/html/

EXPOSE 80