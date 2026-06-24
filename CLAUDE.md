# Conventions UI — LVL UP

## Inputs / champs de formulaire

Tous les inputs, selects et textareas doivent utiliser `bg-gray-50` (fond légèrement gris) pour créer du contraste sur les cartes blanches (`bg-white`). Ne jamais utiliser `bg-white` sur un input posé sur une carte blanche.

```html
<input class="block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition">
<select class="block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition">
```

En cas d'erreur, remplacer `border-gray-300` par `border-red-400`.

---

## Breadcrumb

Le breadcrumb est défini via `{% block breadcrumb %}` dans chaque template enfant. Il est rendu automatiquement en inline (avant le contenu, dans la zone paddée) par `school.html.twig` et `admin.html.twig`. Ne pas le mettre dans le contenu du block principal.

### Séparateur : chevron SVG

```twig
{% block breadcrumb %}
<a href="{{ path('route_parent') }}" class="hover:text-gray-700 transition">Label parent</a>
<svg class="w-3.5 h-3.5 text-gray-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
<span class="text-gray-700 font-medium">Page courante</span>
{% endblock %}
```

### Règles
- Les liens parents : `class="hover:text-gray-700 transition"`
- La page courante : `<span class="text-gray-700 font-medium">` (pas de lien)
- Pages de premier niveau (ex: Tableau de bord, Mon profil) : pas de `{% block breadcrumb %}`, pas de nav affichée
- Pages school : commencer par `team.name` avec lien vers `school_home`
- Pages admin : commencer par `Tableau de bord` avec lien vers `app_admin`

### Exemple complet (page de sous-section)

```twig
{% block breadcrumb %}
<a href="{{ path('school_home') }}" class="hover:text-gray-700 transition">{{ team.name }}</a>
<svg class="w-3.5 h-3.5 text-gray-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
<a href="{{ path('school_settings') }}" class="hover:text-gray-700 transition">Paramètres</a>
<svg class="w-3.5 h-3.5 text-gray-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
<span class="text-gray-700 font-medium">Saisons</span>
{% endblock %}
```

---

## Base de données — schéma

Ne jamais créer de fichier de migration Doctrine. Pour appliquer les changements de schéma (ajout de colonne, modification d'entité…), utiliser :

```bash
php bin/console doctrine:schema:update --force
```

---

## Layout général

- Pas de `max-w-*` sur les pages : tout prend la largeur disponible
- Pas de chevron retour dans les titres de page
- Pas de `bg-gray-50` dans le footer d'une card de formulaire (pas de bande grise sous le bouton)
- Le `h1` de la page est toujours `text-2xl font-bold text-gray-900` avec `mb-6`
