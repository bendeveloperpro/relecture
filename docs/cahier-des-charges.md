# Relecture — cahier des charges fonctionnel et technique

_Version 0.1.0 · 13 octobre 2026 · état : V1 réalisée et vérifiée (§ 14)_

Extension WordPress qui permet de relire, corriger et annoter le texte d'articles et de
pages dans un tableau à trois colonnes, puis d'exporter la relecture pour un tiers ou une IA.
Les notes peuvent devenir des consignes pour les prochains contenus.

---

## 1. Contexte et objectifs

Aujourd'hui, la relecture d'un article se fait hors de WordPress (copier-coller dans un
traitement de texte, captures annotées, mails), ou dans l'éditeur bloc par bloc, sans vue
d'ensemble ni trace exploitable. Quand les articles sont produits avec une IA, les remarques
de relecture se perdent au lieu d'améliorer les générations suivantes.

L'extension doit :

1. **montrer tout le texte d'un contenu d'un coup d'œil**, élément par élément (titre,
   intertitres, paragraphes…), avec en face une **correction** et une **note** ;
2. **ne jamais modifier le contenu** : la relecture est un instantané séparé ;
3. **exporter** cette relecture (Excel, CSV, Word, et des formats lisibles par une IA) pour
   qu'un tiers ou une IA la valide et l'applique ;
4. **capitaliser les notes** en consignes réutilisables pour les prochains contenus ;
5. être **sûre**, **open source** (GitHub) et **publiable sur wordpress.org**.

Décisions prises avec Benoit le 13/10/2026 : nom « Relecture » ; relecteurs = comptes qui
peuvent modifier le contenu ; pas d'application automatique des corrections en V1 ; chaînes
et code en anglais, traduction française fournie ; ce document en français.

## 2. Existant

Recherche du 13/10/2026 dans le répertoire wordpress.org (API `plugins/info/1.2`, requêtes
« proofreading », « content review », « editorial review », « copy editing », « annotate
posts », « review corrections », « editorial comments », « content audit », « suggest edits »,
« track changes », « client feedback », « review workflow », « export posts word », « export
content excel », « content approval », « editorial notes »).

**Aucune extension trouvée ne propose le tableau original / correction / note avec export
bureautique.** Les plus proches :

| Extension | Installations actives | Ce qu'elle fait | Différence |
| --- | --- | --- | --- |
| [AI](https://wordpress.org/plugins/ai/) (WordPress.org) | 60 000 | *Editorial Notes* : relecture par IA bloc par bloc, qui pose des Notes dans l'éditeur ; *Editorial Updates* ; *Guidelines* (type de contenu `wp_guideline`, fourni par l'extension Gutenberg) | Relecture par l'IA, dans l'éditeur ; ni tableau, ni export |
| Notes de l'éditeur (cœur, depuis WordPress 6.9) | — | Commentaires attachés à un bloc (`comment_type = 'note'`) | Bloc par bloc, sans vue d'ensemble ni export |
| [Multicollab](https://wordpress.org/plugins/commenting-feature/) | 300 | Commentaires façon Google Docs dans l'éditeur | Pas de tableau ni d'export |
| [PublishPress Revisions](https://wordpress.org/plugins/revisionary/) | 10 000 | Révisions soumises puis approuvées | Workflow de publication, pas de relecture annotée |
| [Edit Flow](https://wordpress.org/plugins/edit-flow/) | 4 000 | Statuts et commentaires éditoriaux | Commentaires au niveau de l'article |
| [Peter's Post Notes](https://wordpress.org/plugins/peters-post-notes/) | 3 000 | Notes d'administration sur un contenu | Notes globales, pas par élément |
| [WP Spell Check](https://wordpress.org/plugins/wp-spell-check/), [WProofreader](https://wordpress.org/plugins/webspellchecker/), [Proofreading](https://wordpress.org/plugins/proofreading/) | 2 000 à 5 000 | Correcteurs automatiques | Vérification automatique, pas de relecture humaine |

**Positionnement** : une relecture humaine de tout le contenu, d'un coup d'œil, qui se
transmet (bureautique ou IA) et nourrit les consignes de rédaction.

**Socle disponible** : WordPress 7.0 a intégré au cœur un client IA (`wp_ai_client_prompt()`,
`wp_supports_ai()`) et les Connecteurs (Réglages → Connecteurs) ; WordPress 6.9 a apporté
l'API Abilities (`wp_register_ability()`, exposable en REST et MCP) et les Notes. La V2 pourra
s'appuyer dessus sans que l'extension stocke de clé d'API (§ 17).

## 3. Nom

**Relecture** : slug `relecture` libre sur wordpress.org le 13/10/2026 (réponse « Plugin not
found » de l'API `plugin_information`).

Noms écartés lors de la recherche :

| Nom | Raison |
| --- | --- |
| Copydesk | QuarkCopyDesk, logiciel éditorial existant |
| Blue Pencil | Zoho BluePencil, correcteur existant |
| Red Pen | slug `redpen` fermé, et un outil redpen.io existe |
| Marginalia | slug fermé |
| Margin Notes | trop proche de l'application MarginNote |
| Errata | libre, mais voisin de [WP Errata](https://wordpress.org/plugins/wp-errata/) (abandonnée) |

Le mot est français : la description courte, en anglais, porte la découvrabilité sur
wordpress.org.

## 4. Utilisateurs et droits

| Acteur | Peut | Comment |
| --- | --- | --- |
| Relecteur | relire, corriger, annoter, exporter un contenu | tout compte qui peut **modifier** ce contenu (`edit_post`) : administrateur et éditeur sur tout, auteur sur ses contenus |
| Valideur (tiers) | accepter ou refuser les corrections | avec un compte éditeur (statuts dans le tableau), ou hors de WordPress via l'export Word ou Excel |
| IA ou script | lire les relectures, marquer les corrections appliquées | export JSON ou Markdown, API REST avec mot de passe d'application, WP-CLI |

- Le menu exige `edit_posts`.
- Les listes ne montrent à un auteur que ses propres contenus.
- La page Consignes ne montre que les consignes des contenus que l'utilisateur peut modifier.
- Une règle sur mesure passe par le filtre `relecture_can_review`.

## 5. Fonctionnalités de la V1

Chaque fonctionnalité liste ses **critères de validation**, tous vérifiés (§ 14).

### 5.1 Ouvrir une relecture

On ouvre une relecture depuis :
- le menu **Relecture → Contenus** : liste des contenus relisables, avec le type, l'état de
  publication, l'état de la relecture, les nombres de corrections et de notes et la dernière
  modification ; filtres par type et par état, recherche, tri par titre ;
- le lien **Relire** sous chaque contenu des listes Articles et Pages ;
- **Relire cette page** dans la barre d'administration, sur le site.

Ouvrir un contenu jamais relu crée la relecture : c'est un instantané du titre, de l'extrait
et des blocs de texte.

_Critères_ :
- le lien de démarrage porte un jeton de sécurité ; sans lui, rien n'est créé ;
- rouvrir un contenu déjà en relecture ne crée pas de doublon.

### 5.2 Relire : le tableau

Le tableau a trois colonnes : **Texte original · Correction · Note**. Le numéro de ligne et
le type d'élément (Titre, H2, Paragraphe, Élément de liste, Légende, Texte alternatif,
Cellule…) s'affichent dans la colonne de l'original.

- **Correction**
  - Au clic, le champ vide est prérempli avec l'original, à retoucher. L'option « Partir du
    texte original pour corriger » peut être décochée, et ce choix est mémorisé par
    navigateur.
  - Laissé tel quel, le préremplissage s'efface. Une correction identique à l'original ne
    compte pas.
  - Le bouton « Copier le texte original » remet l'original dans le champ.
  - Le statut de la correction est « À valider », « Acceptée », « Refusée » ou
    « Appliquée ». Il n'apparaît que lorsqu'une correction existe.
- **Note** : texte libre, plus la case **« Consigne pour les prochains contenus »**.
- **Enregistrement automatique**
  - Il se déclenche après une pause de frappe (1,2 s), à la sortie d'un champ, au
    changement d'un statut ou d'une case, et avec Ctrl/Cmd + S.
  - L'état (« Modifications non enregistrées », « Enregistrement… », « Tout est
    enregistré ») est annoncé aux lecteurs d'écran.
  - En cas d'échec, un bouton « Réessayer » s'affiche.
  - Le navigateur prévient avant de quitter une page qui a des modifications en attente.
- **Conflits** : deux personnes sur la même ligne ne s'écrasent jamais. La seconde reçoit
  « Quelqu'un d'autre a modifié cette ligne entre-temps » et rien n'est perdu.
- **Filtres** : Toutes les lignes, Avec retour, Sans retour.
- **Compteurs** : Corrections, Notes, Consignes.
- **Mise en page**
  - La barre d'outils et les en-têtes de colonnes restent visibles au défilement.
  - Sous 782 px, chaque ligne devient une carte.
- **Compositions synchronisées** : elles s'affichent en ligne d'information, avec un lien
  pour les relire à part.

_Critères_ :
- la saisie s'enregistre et survit au rechargement ;
- le préremplissage non modifié revient à vide ;
- les compteurs suivent la saisie ;
- l'affichage tient à 390, 768 et 1440 px, sans défilement horizontal.

### 5.3 Statuts et tours de relecture

```
En cours ──► Relecture terminée ──► Corrections intégrées (lecture seule)
   ▲                 │                        │
   └──── revenir ────┘     rouvrir (dernier tour) / nouveau tour
```

- **Relecture terminée** : la relecture est prête pour le tiers ou l'IA.
- **Corrections intégrées** : la relecture est close et ses champs sont en lecture seule.
  Seules les consignes qu'elle a produites gardent leur propre statut.
- Un **nouveau tour** peut ensuite commencer. Les tours précédents restent consultables
  (« Tours : n° 2 (en cours) · n° 1 (corrections intégrées) »).
- Seul le dernier tour peut être rouvert : on n'a jamais deux relectures ouvertes sur un même
  contenu.

### 5.4 Resynchronisation

Si le contenu change après le début de la relecture (empreinte SHA-256 du titre, de l'extrait
et du contenu), un avis propose **« Mettre à jour le texte original »**. Cela ne se fait
jamais automatiquement.

La resynchronisation :
1. relit le contenu ;
2. rattache corrections et notes à leur ligne par empreinte (type + bloc + texte exact),
   dans l'ordre de lecture pour les doublons ;
3. ajoute les nouvelles lignes ;
4. met à part, dans une section dédiée, les lignes porteuses d'un retour dont le texte a
   disparu, et supprime les autres.

_Critère_ : après modification d'un paragraphe annoté, la relecture garde ses 31 lignes
intactes, ajoute la nouvelle et met l'ancienne de côté.

### 5.5 Exports

Depuis l'écran de relecture : choix du format, option **« Seulement les lignes avec
retour »**.

| Format | Pour | Contenu |
| --- | --- | --- |
| Excel (.xlsx) | tiers, tableur | feuille « Relecture » (en-tête figé, filtre automatique, retours à la ligne) et feuille « Infos » |
| Word (.docx) | tiers, impression | A4 paysage, informations en tête, tableau N° / Élément / Original / Correction / Note (en-tête répété sur chaque page), consignes en fin de document |
| CSV | tableur, import | UTF-8 avec BOM ; séparateur `;` si la virgule est le séparateur décimal de la langue du site (français…), sinon `,` |
| Markdown | IA, lecture humaine | informations, puis une section par ligne (bloc, chemin, original, correction et statut, note) ; textes en blocs de code « verbatim » |
| JSON | IA, script | structure `relecture/review@1` : contenu, relecture, compteurs, lignes (avec le HTML source de chaque ligne), consignes, compositions |

Colonnes du CSV et de l'Excel : N°, Élément, Texte original, Correction, Statut de la
correction, Note, Type de note, Bloc, Chemin, ID.

_Critères_ :
- les cinq formats s'ouvrent dans des outils indépendants (LibreOffice pour Excel et Word) ;
- les charges XSS restent du texte ;
- les formules sont neutralisées.

### 5.6 Consignes

La page **Relecture → Consignes** rassemble toutes les notes cochées « consigne », tous
contenus confondus. Pour chacune, on voit :
- le texte de la consigne ;
- l'élément et le texte à propos duquel elle a été écrite ;
- le contenu source (lien vers la relecture) ;
- son statut ;
- sa date.

On y trouve aussi :
- les vues **Nouvelles / Intégrées / Toutes** ;
- les actions groupées **Marquer comme intégrées / nouvelles** ;
- un **export** Markdown (à coller dans un prompt ou une charte éditoriale), CSV ou JSON.

Une consigne intégrée dont le texte change redevient « nouvelle ».

### 5.7 Passage de relais à une IA ou à un script

Une IA ou un script dispose de trois accès :
- **API REST** `relecture/v1`, avec mot de passe d'application (§ 10) ;
- **WP-CLI** `wp relecture …` (§ 11) ;
- **exports JSON ou Markdown**.

Exemple, pour un site dont le contenu est généré depuis des fichiers (compositions de thème) :
1. `wp relecture export <id> --format=md --only-with-feedback` ;
2. l'IA corrige les fichiers sources (le nom de bloc, le chemin et le HTML source situent
   chaque texte) ;
3. `wp relecture set-item-status <ids> --correction=applied` puis
   `wp relecture set-status <relecture> done`.

## 6. Règles de gestion

- **Segments** : ce sont les lignes du tableau, dans l'ordre de lecture.
  - Titre et extrait (s'il n'est pas vide).
  - Texte propre de chaque bloc : le texte d'un bloc sans celui de ses blocs internes. Le
    texte propre d'une citation est sa source, placée après les paragraphes cités ; celui
    d'un bloc Détails est son résumé.
  - Légendes (image, vidéo, audio, galerie, tableau, embed) et textes alternatifs des images.
  - Cellules de tableau (avec ligne et colonne).
  - Éléments de liste, y compris imbriqués.
  - Citation en exergue et sa source.
  - Vers et préformaté, espaces et sauts de ligne conservés.
  - Texte visible du HTML personnalisé.
  - Contenu classique : `wpautop`, puis un segment par paragraphe, intertitre, élément de
    liste, cellule, légende ou texte alternatif. Le texte de `[caption]` est gardé ; les
    autres shortcodes sont retirés.
- **Exclus** : code, shortcode, séparateur, espaceur, blocs dynamiques (derniers articles,
  navigation…), scripts et styles. La liste est filtrable (`relecture_excluded_blocks`).
- **Texte** : les espaces HTML sont réduites, les `<br>` deviennent des sauts de ligne, les
  entités sont décodées. Les **espaces insécables sont conservées** (typographie française).
- **Saisie** : texte brut d'au plus 20 000 caractères.
  - Les caractères de contrôle sont retirés et l'UTF-8 est validé.
  - Le HTML tapé reste du texte (une note peut parler de « `<h2>` ») : il est échappé à
    l'affichage.
- **Suppression** :
  - supprimer définitivement un contenu supprime ses relectures ;
  - la mise à la corbeille ne touche à rien ;
  - la suppression d'une révision est ignorée.

## 7. Exigences non fonctionnelles

| Domaine | Exigence |
| --- | --- |
| Sécurité | droits vérifiés à chaque point d'entrée, jetons sur tous les formulaires, échappement à chaque sortie, SQL préparé, aucun fichier d'export stocké (§ 13) |
| Confidentialité | aucune requête externe, aucun suivi, aucune ressource distante ; seules données personnelles : l'ID des utilisateurs auteurs des modifications |
| Performance | une requête pour les lignes d'une relecture, une pour les compteurs d'une page de liste ; aucune requête par ligne |
| Accessibilité | en-têtes de tableau (`th` de ligne et de colonne), champs étiquetés, région `aria-live`, boutons de filtre `aria-pressed`, cartes sous 782 px |
| Langues | chaînes sources en anglais (domaine `relecture`), traduction fr_FR fournie (.po, .mo, .l10n.php, JSON pour le JavaScript) |
| Compatibilité | WordPress 6.5 et plus (testé 6.5 et 7.1.3), PHP 7.4 et plus (syntaxe vérifiée en 7.4, exécution en 8.3), multisite (tables par site, nettoyage à la suppression d'un site) |
| Maintenabilité | aucune étape de build ; JS et CSS livrés lisibles ; WordPress Coding Standards |

## 8. Architecture technique

```
relecture.php                 en-têtes, constantes RELECTURE_*, chargement, activation
uninstall.php                 suppression des tables et options (sauf RELECTURE_KEEP_DATA)
includes/
  class-plugin.php            hooks communs, URL, barre d'admin, WP-CLI
  class-installer.php         schéma dbDelta, montée de version, multisite
  class-permissions.php       types relisables, droit de relire
  class-repository.php        accès aux deux tables (requêtes préparées)
  class-text.php              HTML → texte, nettoyage des saisies
  class-labels.php            libellés traduits (statuts, segments)
  class-html-fragment.php     accès structuré au HTML (DOMDocument, repli si absent)
  class-extractor.php         contenu → segments
  class-review-service.php    règles : démarrer, resynchroniser, modifier, statuts
  class-rest-controller.php   API REST relecture/v1
  class-cli-command.php       wp relecture …
  admin/                      menu, écrans (vues PHP), listes (WP_List_Table), formulaires
  export/                     données communes + écrivains CSV, XLSX, DOCX, Markdown, JSON
assets/js/review.js           enregistrement automatique (JS natif, wp-api-fetch, wp-i18n)
assets/css/review.css         écrans d'administration
languages/                    relecture.pot + fr_FR
dev/                          environnement Podman (WordPress + MySQL + WP-CLI) et données de test
tests/                        tests (§ 14)
scripts/                      traductions, construction du zip
```

Flux d'une saisie :
1. `review.js` envoie `PATCH /relecture/v1/items/<id>` avec la version de départ ;
2. `Rest_Controller` vérifie le droit, puis `Review_Service::update_item()` applique les
   règles ;
3. `Repository::update_item()` écrit à condition que la version n'ait pas bougé ;
4. le serveur renvoie la nouvelle version.

## 9. Modèle de données

Deux tables par site, créées par `dbDelta`. La version du schéma est dans l'option
`relecture_db_version`, contrôlée à chaque chargement.

**`{prefix}relecture_reviews`**

| Champ | Contenu |
| --- | --- |
| id, post_id | relecture, contenu relu |
| status | `in_progress`, `ready` ou `done` |
| content_hash | empreinte SHA-256 du titre, de l'extrait et du contenu au dernier instantané |
| created_by/at, updated_by/at, synced_at | traçabilité (dates en UTC) |

Index : (post_id, status).

**`{prefix}relecture_items`**

| Champ | Contenu |
| --- | --- |
| id, review_id, position | ligne, relecture, ordre de lecture |
| segment_type | `title`, `excerpt`, `block`, `caption`, `alt`, `citation`, `summary`, `cell`, `item`, `classic` ou `reference` |
| block_name, block_path, meta | bloc d'origine, chemin d'index dans l'arbre (ex. `6.1.0.0`), données (niveau de titre, ligne et colonne, balise…) |
| fingerprint | empreinte SHA-1 du type, du bloc et du texte (resynchronisation) |
| original, original_html | texte de l'instantané, HTML source |
| correction, correction_status | correction (NULL si aucune) ; `pending`, `accepted`, `rejected` ou `applied` |
| note, note_type, guideline_status | note ; `post` ou `guideline` ; `new` ou `integrated` |
| orphaned | texte disparu du contenu |
| version | verrou optimiste, incrémenté à chaque modification visible |
| updated_by/at | auteur et date de la dernière modification |

Index : (review_id, position), (note_type, guideline_status).

Les libellés ne sont jamais stockés : ils sont calculés à l'affichage, dans la langue de
l'utilisateur.

## 10. API REST `relecture/v1`

| Méthode | Route | Paramètres | Réponse |
| --- | --- | --- | --- |
| GET | `/reviews` | `post_id`, `status`, `page`, `per_page` (≤ 100) | relectures accessibles à l'utilisateur |
| POST | `/reviews` | `post_id` (requis), `new_round` | relecture complète ; 201 si créée, 200 si existante |
| GET | `/reviews/<id>` | `only_with_feedback` | structure de l'export JSON |
| PATCH | `/reviews/<id>` | `status` (enum) | informations de la relecture ; 409 si l'on rouvre un ancien tour |
| POST | `/reviews/<id>/resync` | — | compteurs (kept, added, orphaned, removed) ; 409 si close |
| GET | `/items/<id>` | — | une ligne |
| PATCH | `/items/<id>` | `correction`, `note` (≤ 20 000), `correction_status`, `note_type`, `guideline_status` (enums), `expected_version` | la ligne ; 409 si version périmée ou relecture close |
| GET | `/guidelines` | `status` (new, integrated, all), `page`, `per_page` | consignes accessibles |

Codes d'erreur :
- 401 hors connexion ;
- 403 sans le droit, ou pour un objet inexistant (on ne révèle pas son existence) ;
- 400 pour une valeur invalide ;
- 409 en cas de conflit.

## 11. WP-CLI

| Commande | Rôle |
| --- | --- |
| `wp relecture list [--status=] [--post=] [--format=]` | lister les relectures (table, csv, json, yaml) |
| `wp relecture start <post> [--new-round]` | démarrer ou rouvrir la relecture d'un contenu |
| `wp relecture export <post> [--review=] [--format=json\|md\|csv\|xlsx\|docx] [--only-with-feedback] [--output=]` | exporter (sortie standard pour les formats texte) |
| `wp relecture guidelines [--status=new\|integrated\|all] [--format=md\|json\|csv] [--output=]` | exporter les consignes |
| `wp relecture set-status <review> <in_progress\|ready\|done>` | changer le statut d'une relecture |
| `wp relecture set-item-status <item>... [--correction=] [--guideline=]` | changer le statut de lignes |

## 12. Formats d'export : détails de réalisation

- **XLSX et DOCX** sont écrits à la main en Office Open XML minimal, sans bibliothèque.
  - Le zip passe par `ZipArchive`, ou à défaut par PclZip, fourni avec WordPress.
  - Le fichier temporaire est créé par `wp_tempnam()` et supprimé après l'envoi.
  - Les caractères interdits en XML 1.0 sont retirés, et le texte est échappé.
- **XLSX** : les textes sont des « chaînes en ligne », jamais évaluées par le tableur.
- **CSV** : une cellule qui commence par `=`, `+`, `-`, `@`, une tabulation ou un retour
  chariot est préfixée d'une apostrophe (recommandation
  [OWASP CSV Injection](https://owasp.org/www-community/attacks/CSV_Injection)). Le coût
  visible : « -5 °C » s'affiche « '-5 °C ».
- **Markdown** : les textes sont dans des blocs de code délimités plus longs que toute suite
  d'accents graves du texte. Ils sont donc rendus tels quels, et le HTML hors de ces blocs
  est échappé.
- **Téléchargement** : `admin-post.php`, jeton par relecture, liste blanche de formats,
  en-têtes `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` et
  `no-cache`.

## 13. Sécurité

| Menace | Parade |
| --- | --- |
| Accès à la relecture d'un contenu qu'on ne peut pas modifier (ID deviné) | chaque route, écran et export remonte de l'objet au contenu et vérifie `edit_post` |
| Requête forgée depuis un autre site (CSRF) | jeton sur les liens de démarrage, les formulaires de statut, de resynchronisation et d'export, et les actions groupées ; jeton `wp_rest` pour l'API depuis l'administration |
| XSS par le contenu relu ou les saisies | stockage en texte brut, échappement à chaque sortie : `esc_html`, `esc_textarea` et `esc_attr` en PHP, `textContent` et `value` en JS (jamais `innerHTML`), XML, Markdown |
| Injection SQL | requêtes fixes et préparées, noms de tables en `%i`, colonnes sur liste blanche, tri sur liste blanche |
| Injection de formules (CSV) | neutralisation ; l'XLSX n'a que des chaînes |
| Fuite par fichiers | aucun export stocké dans `uploads` ; fichier temporaire supprimé aussitôt |
| Écrasement concurrent | verrou optimiste par ligne, réponse 409 |
| Accès direct aux fichiers PHP | garde `ABSPATH` dans chaque fichier |
| Données résiduelles | `uninstall.php` supprime tables et options sur chaque site du réseau |

## 14. Qualité et tests (état au 13/10/2026)

| Contrôle | Commande | Résultat |
| --- | --- | --- |
| Tests fonctionnels : extraction, droits, service, resynchronisation, statuts, exports | `wp eval-file …/tests/smoke.php` | 52 vérifications, toutes réussies, sur WordPress 7.1.3 et 6.5 |
| Exports ouverts par des outils indépendants (zip, xmllint, LibreOffice) | `tests/check-exports.sh` | 14 vérifications réussies |
| Droits et validation de l'API REST, en HTTP réel avec mots de passe d'application | `tests/security.sh` | 22 vérifications réussies, sur 7.1.3 et 6.5 |
| Plugin Check (outil officiel de wordpress.org), sur les fichiers distribués | `wp plugin check relecture …` | 0 erreur, 0 avertissement |
| WordPress Coding Standards (Extra + Docs) et PHPCompatibilityWP (PHP 7.4+) | `vendor/bin/phpcs` | aucun écart |
| Syntaxe PHP 7.4 | `php -l` dans `php:7.4-cli` | aucune erreur |
| Navigateur | à la main | saisie, préremplissage, enregistrement, filtres, consignes, actions groupées, statuts, lecture seule, charges XSS affichées comme du texte, mise en page à 390, 768 et 1440 px, interface en français |

## 15. Conformité wordpress.org

D'après les [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
et les [Common Issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/) :

| Règle | État |
| --- | --- |
| Licence GPL compatible, déclarée à l'identique dans l'en-tête et le readme | GPLv2 or later, fichier LICENSE |
| Code lisible, sources disponibles | JS et CSS non minifiés, sans build |
| Aucun appel externe ni suivi sans consentement | aucun |
| Pas de bibliothèque déjà présente dans le cœur | PclZip et wp-api-fetch sont ceux du cœur |
| Préfixes uniques | espace de noms `Relecture\`, préfixe `relecture_`, constantes `RELECTURE_*` |
| Nettoyer, valider, échapper ; jetons ; requêtes préparées | oui (§ 13) |
| `readme.txt` valide, Stable tag = version, description courte ≤ 150 caractères | oui (134 caractères) |
| Domaine de traduction = slug | `relecture` |
| Pas de fichiers de développement dans le zip | `.distignore`, `scripts/build-zip.sh` |
| `composer.json` présent | oui (outils de développement seulement) |

## 16. Publication

1. **GitHub** : dépôt public https://github.com/bendeveloperpro/relecture, avec
   « Private vulnerability reporting » activé (SECURITY.md y renvoie). Les commits portent
   l'adresse noreply de GitHub.
2. **wordpress.org**, à faire sur demande :
   - compte `bendeveloperpro`, déjà inscrit dans `Contributors:` du readme ;
   - icône et captures d'écran prêtes dans `.wordpress-org/` ; bannière facultative ;
   - passer la version à 1.0.0 ;
   - soumettre le zip et demander le slug `relecture` ;
   - après validation, publier par SVN (trunk, tags/x.y.z, assets).
3. Une fois l'extension hébergée, la traduction française sera reprise sur
   translate.wordpress.org.

## 17. Feuille de route

**V1.1**
- Différence mot à mot entre original et correction (écran et exports).
- Affichage des espaces insécables.
- API Abilities :
  - lecture des relectures et des consignes ;
  - changement des statuts ;
  - accès pour les agents IA via MCP.
- Exportateur et effaceur de données personnelles (outils RGPD de WordPress).
- Intégration continue GitHub : PHPCS, Plugin Check, tests.
- Vue d'impression.

**V2**
- Appliquer les corrections acceptées depuis WordPress : remplacement dans les blocs, en
  gardant le balisage (gras, liens) quand c'est possible, et création d'une révision.
- Relecture ou propositions par l'IA via le client IA de WordPress 7.0+ :
  - action explicite de l'utilisateur ;
  - fournisseur choisi dans Réglages → Connecteurs, sans clé stockée par l'extension ;
  - service externe documenté dans le readme.
- Export Word avec suivi des modifications, pour une relecture tierce dans Word.
- Import CSV ou XLSX.
- Lien de relecture sans compte (jeton secret à durée limitée).
- Rôle « Relecteur ».
- Synchronisation avec les Notes de l'éditeur et avec les Guidelines.
- Champs SEO (titre et méta-description des extensions courantes).
- Gabarits FSE.

## 18. Points ouverts

- Banner wordpress.org (1544 × 500 et 772 × 250) : facultative, pas encore faite.
- Soumission à wordpress.org : à décider (passage en 1.0.0, § 16).
