// Undo and redo over snapshots of the label (JSON strings).

export class History {
  constructor(limit = 100) {
    this.limit = limit;
    this.past = [];
    this.future = [];
    this.current = null;
  }

  /** Starts over with this snapshot and forgets the rest. */
  reset(snapshot) {
    this.past = [];
    this.future = [];
    this.current = snapshot;
  }

  /** Records a new state; unchanged states are ignored. @returns {boolean} recorded */
  push(snapshot) {
    if (snapshot === this.current) return false;
    if (this.current !== null) this.past.push(this.current);
    if (this.past.length > this.limit) this.past.shift();
    this.current = snapshot;
    this.future = [];
    return true;
  }

  get canUndo() {
    return this.past.length > 0;
  }

  get canRedo() {
    return this.future.length > 0;
  }

  /** @returns {string|null} the state to go back to */
  undo() {
    if (!this.canUndo) return null;
    this.future.push(this.current);
    this.current = this.past.pop();
    return this.current;
  }

  /** @returns {string|null} the state to go forward to */
  redo() {
    if (!this.canRedo) return null;
    this.past.push(this.current);
    this.current = this.future.pop();
    return this.current;
  }
}
