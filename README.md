# Family App Backend

API backend de l’application mobile **FamilyFlow**, développée avec **Laravel**.

Ce projet expose une API REST dédiée à la gestion d’un environnement familial partagé : foyers, membres, tâches, calendrier, budget, recettes, listes de courses, notifications et sondages de repas.

---

## À propos

**Family App Backend** est le backend de l’application mobile **Family App**.

L’objectif du projet est de fournir une API claire, sécurisée et maintenable pour orchestrer les principaux usages d’un foyer partagé :

- gestion des comptes utilisateurs ;
- gestion des foyers et de leurs membres ;
- organisation des tâches ;
- gestion du calendrier familial ;
- suivi budgétaire ;
- recettes et repas ;
- listes de courses ;
- notifications ;
- sondages autour des repas.

Le backend est pensé dans une logique **mobile-first**, sans dépendance à une interface d’administration web interne.

---

## Stack technique

- **PHP 8.4**
- **Laravel 12**
- **Laravel Sanctum**
- **Laravel Reverb / Broadcasting**
- **PHPUnit**

---

## Architecture du projet

Le projet suit une organisation orientée métier afin de limiter la logique dans les controllers et de mieux répartir les responsabilités.

Principaux blocs utilisés :

- `Controllers`
- `Actions`
- `DTOs`
- `Queries`
- `Resources`
- `Policies`
- `Services`

### Principes de structuration

- les **controllers** restent fins et centrés sur l’HTTP ;
- les **actions** portent les cas d’usage métier ;
- les **DTOs** structurent les données entrantes/sortantes ;
- les **queries** isolent la lecture et la récupération de données ;
- les **resources** standardisent les réponses API ;
- les **policies** encadrent les autorisations ;
- les **services** centralisent les traitements transverses ou métier complexes.

Cette approche facilite :

- la lisibilité du code ;
- la testabilité ;
- la séparation des responsabilités ;
- l’évolution progressive des domaines métier.

---

## Authentification

L’API utilise **Laravel Sanctum** pour l’authentification des utilisateurs.

### Routes publiques principales

- `POST /api/login`
- `POST /api/register`
- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`

### Middlewares principaux

Les routes protégées utilisent notamment :

- `auth:sanctum`
- `throttle:api`
- `must.change.password`

---

## Contexte de foyer

Une partie de l’API fonctionne avec la notion de **foyer courant**.

Ce contexte est résolu via le middleware :

- `household.context`

Le foyer actif peut être transmis via le header suivant :

- `X-Household-Id`

Une fois résolu, le middleware injecte dans la requête :

- `current_household`
- `current_household_role`

### Convention de scope

Le projet distingue deux types de routes :

#### Routes `user-scoped`

Ces routes dépendent uniquement de l’utilisateur authentifié.

Exemples :
- compte utilisateur ;
- certaines notifications ;
- préférences personnelles.

#### Routes `household-scoped`

Ces routes dépendent du **foyer courant** et doivent passer par le middleware `household.context`.

Exemples :
- tâches ;
- calendrier ;
- budget ;
- membres du foyer ;
- listes de courses ;
- sondages repas.

Cette séparation permet de clarifier les responsabilités métier et de sécuriser explicitement les accès en fonction du contexte.

---

## Domaines métier couverts

Le backend couvre actuellement les domaines suivants :

- **Auth / Compte**
- **Households**
- **Dashboard**
- **Recipes**
- **Meal Polls**
- **Tasks**
- **Calendar**
- **Notifications**
- **Shopping Lists**
- **Budget**

## Installation

### 1. Cloner le dépôt

git clone <URL_DU_DEPOT>
cd backend

### 2. Installer les dépendances

composer install

### 3. Initialiser l’environnement

cp .env.example .env
php artisan key:generate

### 4. Préparer la base de données

php artisan migrate
php artisan db:seed

## Configuration

Avant de lancer le projet, compléter le fichier .env selon l’environnement cible.

Les éléments à configurer dépendent notamment de :

- la base de données ;
- le mail ;
- les queues ;
- le broadcasting / Reverb ;
- les éventuelles variables liées à des services IA.
Exemple minimal de points à vérifier
- APP_NAME
- APP_ENV
- APP_URL
- DB_*
- MAIL_*
- QUEUE_CONNECTION
- variables de broadcasting
- variables des rate limiters

## Lancement en local

### API
php artisan serve

### Services complémentaires selon les besoins
Worker de queue
php artisan queue:work
Reverb/broadcasting
php artisan reverb:start

## Tests

### Lancer toute la suite de tests
php artisan test
### Tests ciblés
php artisan test --filter=BudgetApiTest
php artisan test --filter=CalendarApiTest
php artisan test --filter=TaskApiTest
php artisan test --filter=NotificationApiTest

L’objectif est de maintenir une bonne couverture des cas fonctionnels API, en particulier sur les domaines sensibles au contexte de foyer.

## Inspection des routes

### Afficher toutes les routes

php artisan route:list

## Etat actuel du projet

Le backend est aujourd’hui structuré comme une API mobile-first, sans dépendance à une interface d’administration web.

### Points de cadrage déjà en place
séparation claire entre routes user-scoped et household-scoped ;
standardisation du middleware household.context ;
sécurisation explicite des routes API ;
couverture de tests fonctionnels orientés API.

## Pistes d'amélioration
Plusieurs axes d’évolution ont déjà été identifiés :

- uniformiser certains noms de routes ;
- continuer à découper les controllers les plus volumineux ;
- renforcer la documentation métier ;
- compléter les tests autour du contexte de foyer ;
- mieux documenter les conventions de réponse API ;
- centraliser davantage certains comportements transverses.