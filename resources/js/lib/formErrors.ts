/**
 * Clears the server error of the field the user is editing, so a fixed field
 * no longer shows the error from the last submit. Bind it once on a container
 * of an Inertia `<Form>`: `@input="clearFieldError($event, clearErrors)"`.
 */
export const clearFieldError = (
    event: Event,
    clearErrors: (...fields: string[]) => void,
): void => {
    const target = event.target;

    if (
        (target instanceof HTMLInputElement ||
            target instanceof HTMLTextAreaElement) &&
        target.name !== ''
    ) {
        clearErrors(target.name);
    }
};
