import { MenuIcon, SearchIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const Navbar = ({ children }) => {
      const logoUrl = '/img/logo-light.svg';
  return (
   <div>
 <header className='bg-background sticky top-0 z-50'>
      <div className='mx-auto flex max-w-7xl items-center justify-between gap-8 px-4 py-7 sm:px-6'>
        <div className='text-muted-foreground flex flex-1 items-center gap-8 font-medium md:justify-center lg:gap-16'>
          <a href='#' className='hover:text-primary max-md:hidden'>
            Home
          </a>
          <a href='#' className='hover:text-primary max-md:hidden'>
            Products
          </a>
          <a href='#'>
            <img
                                    src={logoUrl}
                                    alt="BookNow Logo"
                                    className="h-6 w-6 object-contain text-foreground gap-3"
                                    onError={(event) => {
                                        event.currentTarget.style.display = 'none';
                                        event.currentTarget.parentElement?.classList.add('bg-primary');
                                        const fallback = event.currentTarget.nextElementSibling;
                                        if (fallback) {
                                            fallback.style.display = 'block';
                                        }
                                    }}
                                />
         
          </a>
          <a href='#' className='hover:text-primary max-md:hidden'>
            About Us
          </a>
          <a href='#' className='hover:text-primary max-md:hidden'>
            Contacts
          </a>
        </div>

        <div className='flex items-center gap-6'>
          <Button variant='ghost' size='icon'>
            <SearchIcon />
            <span className='sr-only'>Search</span>
          </Button>
          <DropdownMenu>
            <DropdownMenuTrigger className='md:hidden' asChild>
              <Button variant='outline' size='icon'>
                <MenuIcon />
                <span className='sr-only'>Menu</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className='w-56' align='end'>
              <DropdownMenuGroup>
               
                  <DropdownMenuItem>
                    <a href="#">Home</a>
                  </DropdownMenuItem>
              
              </DropdownMenuGroup>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
      
    </header>

     <main className="flex-1">
        {children}
      </main>
    </div>
  );
};

export default Navbar;