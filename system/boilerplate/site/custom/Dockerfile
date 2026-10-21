# Build + watch image for a site's custom/ theme and element work.
#
# HAXCMS_IMAGE supplies the prebuilt HAXcms web assets (build/) and package.json.
# It tracks the latest published image by default; pin a version with
#   docker build --build-arg HAXCMS_IMAGE=ghcr.io/haxtheweb/haxcms-php:<tag> .
ARG HAXCMS_IMAGE=ghcr.io/haxtheweb/haxcms-php:latest
FROM ${HAXCMS_IMAGE} AS haxcms

FROM node:22-slim

COPY --from=haxcms /var/www/html/build /haxcms/build
COPY --from=haxcms /var/www/html/package.json /haxcms/package.json

WORKDIR /haxcms/_sites/site/custom/

COPY package.json package*.json ./
RUN yarn install
COPY . .

CMD [ "npm", "start" ]
