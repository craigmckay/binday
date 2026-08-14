#!/usr/bin/env bash
# Create the binday repo and push it to GitHub.
# Run this from the folder you want to be the repo root (Git Bash on Windows).
set -euo pipefail

GITHUB_USER="craigmckay"
REPO="binday"
GIT_NAME="craigmckay"
GIT_EMAIL="craigamckay@gmail.com"

if [ -d .git ]; then
  echo "A .git folder already exists here. Delete it or cd somewhere else." >&2
  exit 1
fi

git init
git config user.name  "$GIT_NAME"
git config user.email "$GIT_EMAIL"

echo "# binday" >> README.md
git add README.md
# add the bin script too if it's sitting next to this file
[ -f angus_bins.py ] && git add angus_bins.py

git commit -m "first commit"
git branch -M main

# Username embedded in the URL, so git won't ask "Username for https://github.com".
git remote add origin "https://${GITHUB_USER}@github.com/${GITHUB_USER}/${REPO}.git"

git push -u origin main
