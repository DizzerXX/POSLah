import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { ApiService } from '../core/api.service';
import { Ingredient, Recipe } from '../core/models';

/**
 * The recipe editor — where a menu item is defined as a set of ingredients.
 *
 * **Flat, one level deep.** A recipe line points at an ingredient, never at another product.
 * Real kitchens do have sub-recipes — a burger uses house sauce, which is itself mayo and
 * ketchup — and modelling that flat means duplicating the sauce into every dish that uses
 * it. That is a genuine limitation, documented rather than dressed up: nesting needs
 * recursive expansion, cycle detection and cross-level unit conversion, which is a subsystem
 * with no portfolio signal that flat BOM does not already demonstrate.
 *
 * Editing a recipe does not touch sales already made. Expansion happens on the till at sale
 * time, so Monday's burger keeps Monday's ingredients however often this screen is used.
 */
@Component({
  selector: 'app-recipes',
  standalone: true,
  imports: [FormsModule],
  template: `
    <header>
      <h1>Recipes</h1>
      <p class="muted">
        What each menu item is made from. One burger is three rows: bun ×1, patty ×150g,
        cheese ×2.
      </p>
    </header>

    @if (loading()) {
      <p class="muted">Loading…</p>
    } @else if (recipes().length === 0) {
      <div class="empty">
        <p><strong>No menu items yet.</strong></p>
        <p class="muted">A menu item is a product that holds no stock of its own.</p>
      </div>
    } @else {
      @for (recipe of recipes(); track recipe.id) {
        <section>
          <div class="head">
            <div>
              <strong>{{ recipe.name }}</strong>
              <span class="muted">{{ formatMoney(recipe.price) }}</span>
            </div>
            <span class="muted cost" [attr.data-testid]="'cost-' + recipe.id">
              indicative cost {{ formatMoney(recipe.indicative_cost) }}
            </span>
          </div>

          @if (recipe.lines.length === 0) {
            <p class="muted none">
              No ingredients. Selling this writes no stock movements — which is legitimate for
              something like a corkage fee.
            </p>
          }

          <ul>
            @for (line of recipe.lines; track line.id) {
              <li>
                <span class="name">{{ line.ingredient_name }}</span>
                <span class="qty">
                  <input
                    type="text"
                    inputmode="decimal"
                    [ngModel]="line.quantity"
                    (ngModelChange)="edit(recipe.id, line.ingredient_id, $event)"
                    [attr.data-testid]="'qty-' + line.id"
                  />
                  <span class="muted">{{ line.unit }}</span>
                </span>
                <button class="ghost" (click)="remove(recipe, line.ingredient_id)">Remove</button>
              </li>
            }
          </ul>

          <div class="add">
            <select [(ngModel)]="adding[recipe.id]">
              <option [ngValue]="undefined">Add an ingredient…</option>
              @for (ingredient of unusedFor(recipe); track ingredient.id) {
                <option [ngValue]="ingredient.id">{{ ingredient.name }} ({{ ingredient.unit }})</option>
              }
            </select>
            <button class="primary" (click)="add(recipe)" [disabled]="!adding[recipe.id]">Add</button>
            <span class="spacer"></span>
            <button
              class="primary"
              (click)="save(recipe)"
              [disabled]="busy() || !isDirty(recipe.id)"
              [attr.data-testid]="'save-' + recipe.id"
            >
              {{ isDirty(recipe.id) ? 'Save recipe' : 'Saved' }}
            </button>
          </div>
        </section>
      }
    }

    @if (error()) {
      <p class="error">{{ error() }}</p>
    }
  `,
  styles: `
    :host { display: block; max-width: 820px; padding: 32px 28px; }
    h1 { margin: 0; font-size: 24px; letter-spacing: -0.02em; }
    header p { margin: 4px 0 0; max-width: 60ch; }
    .muted { color: #64748b; font-size: 14px; }
    section {
      margin-top: 20px; padding: 18px 20px; background: #fff; border: 1px solid #e2e8f0;
      border-radius: 12px;
    }
    .head { display: flex; justify-content: space-between; align-items: baseline; }
    .head strong { font-size: 16px; margin-right: 10px; }
    .cost { font-size: 13px; }
    .none { margin: 12px 0 0; font-size: 13px; }
    ul { list-style: none; margin: 14px 0 0; padding: 0; display: grid; gap: 8px; }
    li { display: flex; align-items: center; gap: 12px; }
    .name { flex: 1; font-size: 14px; }
    .qty { display: flex; align-items: center; gap: 6px; }
    input { width: 90px; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: right; }
    select { padding: 7px 8px; border: 1px solid #cbd5e1; border-radius: 8px; }
    .add { display: flex; align-items: center; gap: 8px; margin-top: 16px; }
    .spacer { flex: 1; }
    button { cursor: pointer; font-size: 13px; }
    .primary { padding: 8px 14px; border: 0; border-radius: 8px; background: #0f766e; color: #fff; font-weight: 600; }
    .primary:disabled { opacity: 0.5; cursor: default; }
    .ghost { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
    .empty { margin-top: 24px; padding: 40px; border: 1px dashed #cbd5e1; border-radius: 12px; text-align: center; }
    .error { margin-top: 16px; color: #b91c1c; }
  `,
})
export class RecipesComponent {
  private readonly api = inject(ApiService);

  readonly recipes = signal<Recipe[]>([]);
  readonly ingredients = signal<Ingredient[]>([]);
  readonly loading = signal(true);
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  readonly adding: Record<string, string | undefined> = {};
  private readonly dirty = new Set<string>();

  constructor() {
    this.api.ingredients().subscribe((response) => this.ingredients.set(response.data));
    this.load();
  }

  load(): void {
    this.loading.set(true);

    this.api.recipes().subscribe({
      next: (response) => {
        this.recipes.set(response.data);
        this.dirty.clear();
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  isDirty(productId: string): boolean {
    return this.dirty.has(productId);
  }

  unusedFor(recipe: Recipe): Ingredient[] {
    const used = new Set(recipe.lines.map((line) => line.ingredient_id));

    return this.ingredients().filter((ingredient) => !used.has(ingredient.id));
  }

  edit(productId: string, ingredientId: string, quantity: string): void {
    const recipe = this.recipes().find((r) => r.id === productId);
    const line = recipe?.lines.find((l) => l.ingredient_id === ingredientId);

    if (!line) return;

    line.quantity = quantity;
    this.dirty.add(productId);
  }

  add(recipe: Recipe): void {
    const ingredientId = this.adding[recipe.id];
    if (!ingredientId) return;

    const ingredient = this.ingredients().find((i) => i.id === ingredientId);
    if (!ingredient) return;

    recipe.lines.push({
      id: `new-${ingredientId}`,
      product_id: recipe.id,
      ingredient_id: ingredientId,
      quantity: '0.000',
      ingredient_name: ingredient.name,
      unit: ingredient.unit,
      cost_per_unit: ingredient.cost_per_unit,
    });

    this.adding[recipe.id] = undefined;
    this.dirty.add(recipe.id);
    this.recipes.set([...this.recipes()]);
  }

  remove(recipe: Recipe, ingredientId: string): void {
    recipe.lines = recipe.lines.filter((line) => line.ingredient_id !== ingredientId);
    this.dirty.add(recipe.id);
    this.recipes.set([...this.recipes()]);
  }

  save(recipe: Recipe): void {
    this.busy.set(true);
    this.error.set(null);

    const lines = recipe.lines.map((line) => ({
      ingredient_id: line.ingredient_id,
      quantity: line.quantity,
    }));

    this.api.saveRecipe(recipe.id, lines).subscribe({
      next: (response) => {
        this.recipes.set(response.data);
        this.dirty.delete(recipe.id);
        this.busy.set(false);
      },
      error: (response) => {
        this.busy.set(false);
        this.error.set(response.error?.message ?? 'Could not save that recipe.');
      },
    });
  }

  formatMoney(minorUnits: number): string {
    return `RM ${(minorUnits / 100).toFixed(2)}`;
  }
}
