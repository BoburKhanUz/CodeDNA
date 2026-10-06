using System;
using System.Collections.Generic;

namespace Billing
{
    // A comment line.
    public class Ledger : BaseLedger, IDisposable
    {
        private int total;

        public int Add(int amount, params int[] more)
        {
            if (amount < 0 && more.Length == 0)
            {
                throw new ArgumentException("amount");
            }
            foreach (var m in more)
            {
                total += m;
            }
            return total;
        }

        internal static string Label(int value) => value switch { 0 => "zero", 1 => "one", _ => "many" };

        public void Dispose() { }
    }
}
