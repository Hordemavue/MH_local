# MH_local

Ce projet a pour but d'ajouter des applications externes au jeu MyHordes une fois qu'il a été fork (https://gitlab.com/eternaltwin/myhordes/myhordes-docker)

ATTENTION : Toutes les pages que j'ai ajouté ont pour but de jouer EN LOCAL, rien n'a été pensé et sécurisé pour que ce soit utilisé sur un serveur en ligne.

## Modif

La page de la clinique :
"./myhordes-docker/myhordes/packages/myhordes-fixtures/src/content/myhordes/config/clinic.default.yaml"

ATTENTION : J'ai eu la flemme de modifier toute la page donc j'ai juste ajouté les nourritures et paliers qui m'intéressaient

La page compose.yml pour configurérer l'ip local qui servira à accéder aux pages externes dans le "public" (J'ai peut-être fait d'autres modifications dedans, je m'en rappelle plus)
"./myhordes-docker/compose.yml"

La ligne importante a modifier :
  apache:
  .
  .
  .
    ports:
    - "8081:80"
  .
  .
  .

## Ajout

Disclaimer : 90% du projet a été fait ponctuellement avec ChatGPT. Ce qui veut dire que rien n'est optimisé (il y a 3/4 fois la même requête dans le même fichier mais tant que ça fonctionne et que c'est rapide, je touche à rien) 

Toutes les pages ajoutées doivent être mises dans "./myhordes-docker/myhordes/public/" 
