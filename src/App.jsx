import './App.css'

const featuredProperties = [
  {
    title: 'Contemporary Villa with Panoramic Sea Views',
    price: '€4,950,000',
    location: 'Marbella',
    beds: 6,
    baths: 5,
    size: '680 m²',
    image:
      'https://images.unsplash.com/photo-1613977257363-707ba9348227?auto=format&fit=crop&w=1400&q=80',
    badge: 'New',
  },
  {
    title: 'Exclusive Penthouse with Rooftop Terrace',
    price: '€2,850,000',
    location: 'Marbella',
    beds: 4,
    baths: 3,
    size: '320 m²',
    image:
      'https://images.unsplash.com/photo-1600585154526-990dced4db0d?auto=format&fit=crop&w=1400&q=80',
  },
  {
    title: 'Mediterranean Villa with Private Gardens',
    price: '€3,200,000',
    location: 'Estepona',
    beds: 5,
    baths: 4,
    size: '520 m²',
    image:
      'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=1400&q=80',
  },
]

const reasons = [
  {
    title: '25+ Years of Trust',
    text: 'Over two decades of proven expertise in Costa del Sol’s luxury market.',
  },
  {
    title: 'International Reach',
    text: 'Serving discerning clients from over 40 countries worldwide.',
  },
  {
    title: 'Exclusive Access',
    text: 'Off-market properties and pre-launch opportunities for our clients.',
  },
  {
    title: 'White-Glove Service',
    text: 'Personalized concierge service from first viewing to handover.',
  },
]

function App() {
  return (
    <div className="site-shell">
      <header className="hero">
        <div className="hero-overlay" />
        <nav className="hero-nav">
          <div className="brand">
            <span className="brand-main">Elite Properties</span>
            <span className="brand-sub">Spain</span>
          </div>
          <button className="menu-button" aria-label="Open menu">
            <span />
            <span />
            <span />
          </button>
        </nav>

        <div className="hero-content">
          <p className="eyebrow">Costa del Sol · Since 1999</p>
          <h1>
            Exceptional Properties
            <span>for Exceptional Living</span>
          </h1>
          <p className="hero-copy">
            Discover the finest luxury villas, penthouses, and exclusive residences
            across Marbella, Mijas, and Estepona.
          </p>

          <div className="hero-actions">
            <a href="#properties" className="btn btn-gold">View Collection</a>
            <a href="#contact" className="btn btn-outline">Private Consultation</a>
          </div>
        </div>

        <div className="scroll-indicator">↓</div>
      </header>

      <main>
        <section className="section properties-section" id="properties">
          <div className="section-head split">
            <div>
              <p className="eyebrow">Curated Selection</p>
              <h2>Featured Properties</h2>
            </div>
            <a href="#contact" className="view-all">View All →</a>
          </div>

          <div className="properties-grid">
            {featuredProperties.map((property) => (
              <article className="property-card" key={property.title}>
                <div
                  className="property-image"
                  style={{ backgroundImage: `linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.35)), url(${property.image})` }}
                >
                  <div className="property-image-info">
                    <div>
                      <strong>{property.price}</strong>
                      <span>{property.location}</span>
                    </div>
                    {property.badge ? <em>{property.badge}</em> : null}
                  </div>
                </div>
                <h3>{property.title}</h3>
                <div className="property-meta-row">
                  <span>🛏 {property.beds}</span>
                  <span>🛁 {property.baths}</span>
                  <span>◻ {property.size}</span>
                </div>
              </article>
            ))}
          </div>
        </section>

        <section className="trust-section">
          <div className="section-head center light">
            <p className="eyebrow">Why Choose Us</p>
            <h2>A Legacy of Excellence</h2>
          </div>

          <div className="reasons-grid">
            {reasons.map((reason) => (
              <article className="reason-card" key={reason.title}>
                <div className="reason-icon">◇</div>
                <h3>{reason.title}</h3>
                <p>{reason.text}</p>
              </article>
            ))}
          </div>
        </section>

        <section className="section contact-section" id="contact">
          <div className="section-head center narrow">
            <p className="eyebrow">Exclusive Service</p>
            <h2>Begin Your Journey</h2>
            <p>
              Whether you&apos;re searching for your dream home or an investment
              opportunity, our team is here to guide you every step of the way.
            </p>
          </div>

          <div className="contact-card">
            <div className="contact-form-block">
              <h3>Get in Touch</h3>
              <form className="contact-form">
                <input type="text" placeholder="Full Name" />
                <div className="contact-row">
                  <input type="email" placeholder="Email" />
                  <input type="tel" placeholder="Phone" />
                </div>
                <textarea placeholder="Message (optional)" rows="5" />
                <div className="contact-actions">
                  <a className="btn btn-dark" href="mailto:info@elitepropertiesspain.com">Send Request</a>
                  <a className="btn btn-gold" href="https://wa.me/34600000000">WhatsApp</a>
                </div>
              </form>
            </div>
          </div>
        </section>
      </main>

      <footer className="site-footer">
        <div>
          <div className="brand footer-brand">
            <span className="brand-main">Elite Properties</span>
            <span className="brand-sub">Spain</span>
          </div>
          <p>
            Over 25 years of expertise in luxury real estate across the Costa del Sol.
            Your trusted partner for exceptional properties.
          </p>
        </div>
        <div>
          <p className="footer-title">Quick Links</p>
          <a href="#properties">Properties</a>
          <a href="#contact">Contact</a>
        </div>
        <div>
          <p className="footer-title">Contact</p>
          <span>Marbella, Costa del Sol, Spain</span>
          <span>+34 600 000 000</span>
          <span>info@elitepropertiesspain.com</span>
        </div>
      </footer>
    </div>
  )
}

export default App
