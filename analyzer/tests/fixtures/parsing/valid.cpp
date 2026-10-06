#include <vector>
#include "shape.hpp"

// A comment line.
class Circle : public Shape {
public:
    explicit Circle(double r) : radius(r) {}
    double area() const override {
        return radius > 0 ? 3.14 * radius * radius : 0;
    }
private:
    double radius;
    void reset() { radius = 0; }
};

template <typename T>
T clamp(T value, T low, T high) {
    if (value < low) {
        return low;
    } else if (value > high) {
        return high;
    }
    return value;
}
