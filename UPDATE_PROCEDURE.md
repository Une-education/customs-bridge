# Procédure d'Actualisation du Référentiel Douanier (TARIC)

## 1. Origine des Données Sources
- Source : DGDDI (Portail Open Data Douanes FR / Commission Européenne TAXUD).
- Format d'origine : CSV normalisé (`NomenclatureFR.csv` et `NomenclatureEN.csv`).

## 2. Emplacement des Fichiers
- `/var/www/customs-api/data/NomenclatureFR.csv`
- `/var/www/customs-api/data/NomenclatureEN.csv`

## 3. Déroulement d'une Mise à Jour
1. Remplacer les deux fichiers CSV dans `data/`.
2. Lancer l'ingestion et la reconstruction de l'index de recherche FTS5 :
   ```bash
   cd /var/www/customs-api
   php ingest.php data/NomenclatureFR.csv data/NomenclatureEN.csv
