/**
 * Builds one Alpine component out of several parts.
 */

/**
 * Merges the parts of a component into one object, keeping their getters and setters alive.
 *
 * `{ ...part }` would call every getter once and keep the value it answered; this copies the property descriptors
 * instead, so a computed property stays computed. Parts share one `this` once merged: they can call each other's
 * methods and read each other's state. A property a later part defines again replaces the earlier one.
 *
 * @param {...object} parts The pieces of the component, each an object literal of state, getters and methods.
 * @returns {Record<string, any>}
 */
export function compose(...parts) {
    const component = {};

    for (const part of parts) {
        Object.defineProperties(component, Object.getOwnPropertyDescriptors(part));
    }

    return component;
}
