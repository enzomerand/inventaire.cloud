import { describe, expect, it } from 'vitest';

import {
    categoriesOf,
    filterTemplates,
    isSlug,
    linkedTemplate,
    normalizeText,
    withoutLinkedTemplate,
} from '../../../resources/js/inventory-templates/catalogue.js';

/** A line of the catalogue, as `GET /api/v1/templates` gives it. */
function template(slug, name, summary, category = 'home', label = 'Home') {
    return {
        slug,
        category,
        category_label: label,
        icon: 'home',
        name,
        summary,
        fields_count: 3,
        url: `/templates/${slug}`,
    };
}

const CATALOGUE = [
    template('home-inventory', 'Inventaire du logement', 'Tout ce que vous possédez, pour votre assurance.'),
    template('wine-cellar', 'Cave à vin', 'Bouteilles, millésimes et emplacements.', 'food', 'Food'),
    template('it-equipment', 'Parc informatique', 'Ordinateurs, téléphones et licences.', 'it', 'IT'),
    template('moving-boxes', 'Cartons de déménagement', 'Le contenu de chaque carton.'),
];

describe('the slug of a template', () => {
    it.each(['home-inventory', 'it', 'a1-b2-c3', '3d-printing'])('accepts %s', (slug) => {
        expect(isSlug(slug)).toBe(true);
    });

    it.each(['', 'Home', 'home_inventory', 'home--inventory', '-home', 'home-', '../me', 'a b', 'a/b', 'é'])(
        'refuses %j',
        (slug) => {
            expect(isSlug(slug)).toBe(false);
        },
    );

    it('refuses what is longer than the catalogue allows', () => {
        expect(isSlug('a'.repeat(60))).toBe(true);
        expect(isSlug('a'.repeat(61))).toBe(false);
    });
});

describe('the text of the search', () => {
    it('is compared without case, accents or extra spaces', () => {
        expect(normalizeText('  Élément   À  Cœur ')).toBe('element a cœur');
        expect(normalizeText('MILLÉSIMES')).toBe('millesimes');
        expect(normalizeText('')).toBe('');
    });
});

describe('narrowing the templates', () => {
    const names = (templates) => templates.map((entry) => entry.slug);

    it('keeps everything when nothing is asked, in the order of the catalogue', () => {
        expect(names(filterTemplates(CATALOGUE, '', ''))).toEqual([
            'home-inventory',
            'wine-cellar',
            'it-equipment',
            'moving-boxes',
        ]);
        expect(names(filterTemplates(CATALOGUE, '   ', ''))).toHaveLength(4);
    });

    it('searches the name and the summary, whatever the case and the accents', () => {
        expect(names(filterTemplates(CATALOGUE, 'DEMENAGEMENT', ''))).toEqual(['moving-boxes']);
        expect(names(filterTemplates(CATALOGUE, 'millesime', ''))).toEqual(['wine-cellar']);
        expect(names(filterTemplates(CATALOGUE, 'cave a vin', ''))).toEqual(['wine-cellar']);
        expect(names(filterTemplates(CATALOGUE, 'licence', ''))).toEqual(['it-equipment']);
    });

    it('wants every word, in any order, and finds none of what is not there', () => {
        expect(names(filterTemplates(CATALOGUE, 'assurance logement', ''))).toEqual(['home-inventory']);
        expect(names(filterTemplates(CATALOGUE, 'assurance cave', ''))).toEqual([]);
        expect(names(filterTemplates(CATALOGUE, 'zzz', ''))).toEqual([]);
    });

    it('does not read what is typed as a pattern', () => {
        expect(names(filterTemplates(CATALOGUE, '.*', ''))).toEqual([]);
        expect(names(filterTemplates(CATALOGUE, '(', ''))).toEqual([]);
    });

    it('keeps one category, alone or with a search', () => {
        expect(names(filterTemplates(CATALOGUE, '', 'home'))).toEqual(['home-inventory', 'moving-boxes']);
        expect(names(filterTemplates(CATALOGUE, 'carton', 'home'))).toEqual(['moving-boxes']);
        expect(names(filterTemplates(CATALOGUE, 'carton', 'it'))).toEqual([]);
        expect(names(filterTemplates(CATALOGUE, '', 'nope'))).toEqual([]);
    });
});

describe('the categories of the catalogue', () => {
    it('lists each once, in the order they first appear, with their label', () => {
        expect(categoriesOf(CATALOGUE)).toEqual([
            { category: 'home', label: 'Home' },
            { category: 'food', label: 'Food' },
            { category: 'it', label: 'IT' },
        ]);
    });

    it('has none for an empty catalogue', () => {
        expect(categoriesOf([])).toEqual([]);
    });
});

describe('the template an address names', () => {
    it('reads the parameter, with or without the question mark', () => {
        expect(linkedTemplate('?template=wine-cellar')).toBe('wine-cellar');
        expect(linkedTemplate('template=wine-cellar&x=1')).toBe('wine-cellar');
        expect(linkedTemplate('?x=1&template=wine%2Dcellar')).toBe('wine-cellar');
    });

    it('is null when there is none, and empty when the parameter is empty', () => {
        expect(linkedTemplate('')).toBeNull();
        expect(linkedTemplate('?x=1')).toBeNull();
        expect(linkedTemplate('?template=')).toBe('');
    });

    it('takes the parameter out and keeps the others', () => {
        expect(withoutLinkedTemplate('?template=wine-cellar')).toBe('');
        expect(withoutLinkedTemplate('?view=cards&template=wine-cellar&q=a+b')).toBe('view=cards&q=a+b');
        expect(withoutLinkedTemplate('?view=cards')).toBe('view=cards');
        expect(withoutLinkedTemplate('')).toBe('');
    });
});
