#!/usr/bin/env bash

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
. ${DIR}/common.sh

deployment_commit_id=$1

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

for f in ${to_restore[@]}
do
  rm -rf "${webdir}/$f"
done

for f in ${to_restore[@]}
do
  if [ -d "${webdir}_old/$f" ] || [ -f "${webdir}_old/$f" ]; then
    cp -rp "${webdir}_old/$f" "${webdir}"
  else
    echo "Warning: ${webdir}_old/$f does not exist"
  fi
done

bash "$DIR/apply-app.sh" ${deployment_commit_id}
