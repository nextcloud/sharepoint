#!/usr/bin/env bash

APP_NAME=sharepoint

APP_INTEGRATION_DIR=$PWD
ROOT_DIR=${APP_INTEGRATION_DIR}/../../../..
composer install

# shamelessly copy the CommandLine trait from server into out bootstrap dir
cp ${ROOT_DIR}/build/integration/features/bootstrap/CommandLine.php ${APP_INTEGRATION_DIR}/features/bootstrap/

${ROOT_DIR}/occ app:enable files_external
${ROOT_DIR}/occ app:enable ${APP_NAME}
${ROOT_DIR}/occ app:list | grep ${APP_NAME}

export TEST_SERVER_URL="http://localhost:8080/"
${APP_INTEGRATION_DIR}/vendor/bin/behat -f junit -f pretty $1 $2
RESULT=$?

exit $RESULT

