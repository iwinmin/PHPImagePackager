@echo OFF
set BASE=%~dp0

%BASE%php.exe -c %BASE%php.ini -f %BASE%packager.php %*