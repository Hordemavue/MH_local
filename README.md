# MH_local

Ce projet a pour objectif d’ajouter des applications externes au jeu **MyHordes** une fois celui-ci forké via :  
https://gitlab.com/eternaltwin/myhordes/myhordes-docker

⚠️ **Important**  
Toutes les pages ajoutées sont conçues uniquement pour une utilisation **en local**.  
Rien n’a été pensé ni sécurisé pour un déploiement sur un serveur en ligne.

---

## Modifications effectuées

### 1) Page de la clinique

Fichier modifié :

```
./myhordes-docker/myhordes/packages/myhordes-fixtures/src/content/myhordes/config/clinic.default.yaml
```

> ⚠️ Modification partielle uniquement  
> Je n’ai pas modifier l’ensemble du fichier :  
> j’ai simplement modifié les nourritures et paliers qui m’intéressaient.

---

### 2) Configuration `compose.yml`

Fichier :

```
./myhordes-docker/compose.yml
```

Ce fichier permet de configurer le port utilisé pour accéder aux pages externes via le dossier `public`.

> Il est possible que d’autres modifications aient été faites dans ce fichier mais je ne m'en rappelle plus.

#### Ligne importante à modifier pour accéder aux pages dans le public:

```yaml
apache:
  ...
  ...
  ports:
    - "8081:80"
  ...
  ...
```

Le port `8081` correspond à l’accès local via :

```
http://localhost:8081/_citoyens.php?id=?? (via la machine qui contient le docker)
http://[IP locale de la machine qui contient le docker]:8081/_citoyens.php?id=?? (via une autre machine du réseau)
```

---

## Ajout de pages externes

### Disclaimer

Environ 90% du projet a été développé ponctuellement avec ChatGPT.  
Le code n’est pas optimisé :

- Certaines requêtes sont dupliquées plusieurs fois dans un même fichier  
- Il y a probablement des redondances  
- L’architecture n’est pas propre  

Mais tant que :
- ça fonctionne  
- c’est rapide  
- et ça reste en local  

→ je n’y touche pas.

---

### Emplacement des pages ajoutées

Toutes les pages externes doivent être placées dans :

```
./myhordes-docker/myhordes/public/
```

---

## Scripts Python

### `maj_time.py`

Permet de :

- Se connecter sur chaque compte  
- Trigger les objets trouvés  
- Mettre à jour la prochaine fouille dans `_citoyens.php`  
- Vérifier si la case est épuisée ou non  

---

### `carte_j7.py`

Script utilisé pour :

- Mettre à jour la table `zone_perso`  
- Indiquer si une case est :
  - explorée  
  - visitée aujourd’hui  
