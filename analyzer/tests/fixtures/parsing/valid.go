// Package billing computes totals.
package billing

import (
	"errors"
	"strings"
)

// Invoice is an invoice.
type Invoice struct {
	Lines []int
}

type totaler interface{ Total() int }

func (i *Invoice) Total(limit int, strict bool) (int, error) {
	sum := 0
	for _, line := range i.Lines {
		if line < 0 && strict {
			return 0, errors.New("negative")
		}
		sum += line
	}
	switch {
	case sum > limit:
		return limit, nil
	default:
		return sum, nil
	}
}

func normalize(a, b string) string { return strings.TrimSpace(a + b) }
