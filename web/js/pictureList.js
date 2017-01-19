(function($) {
    var app = angular.module('app', ['infinite-scroll']); 
    //directive that cheks if an image is loaded
    /*app.directive('imageonload', function() {
        return {
            restrict: 'A',
            link: function(scope, element, attrs) {
                element.bind('load', function() {
                    return true;
                });
                element.bind('error', function(){
                    return false;
                });
            }
        };
    });*/

    app.controller('pictureController', ['$scope', 'pictureAndPostFactory', function($scope, pictureAndPostFactory) {
        //URL that gets all socialmedia posts that are (primarily) pictures
        $scope.data =[]; //stores past and currenty visible data
        $scope.masterArray =[]; //stores al the data
        $scope.originalArray =[]; // The purpose of this array is to store the original layout of the masterArray
        $scope.address = { //object contains all possible filters for the API
            socialPlatform: "",
            sort: "",
            partyCode: "",
            offset: 0
        };
        //sets default checked radio button when the page loads 
        $scope.radioBtn = "all";
        
        
        //this functions loads more data for the infinite scroll
        // it constantily updates the array from which ng-repeat gets its data
        $scope.loadData = function() {
            //Function that gets the data 
            //it transforms the object into an array and runs
            console.log($scope.address)
            $scope.loading = true;
            pictureAndPostFactory.imageList($scope.address).then(function(successResponse){
                if (successResponse == undefined){
                    $scope.noData = true;
                    $scope.loading = false;
                    return
                };
                $scope.masterArray = $scope.originalArray.concat(successResponse);
                console.log($scope.masterArray);
                $scope.originalArray = $scope.masterArray.slice();
                $scope.loadMore();
                $scope.loading = false;
            });   
        };
        $scope.loadMore = function(){
            infiniteArray()
            if (($scope.data.length/100)==0){
                $scope.loadData()
            } else {
                offset()
                infiniteArray()
            }
        };

        function offset(){
            if (($scope.data.length%100)===0){
                $scope.address.offset += 100
                $scope.loadData();
            };
        };
        //this function slowly serves data from the masterArray
        function infiniteArray(){
            var last = [];
            var x
            if ( $scope.data.length == 0){
                x = -1
            }
            else {
                x = $scope.data.length - 1;
            }
            for(var i = 1; i <= 10; i++) {
                //check if the element is undefined, then there is no more data and the fuction can stop
                var currentValue = $scope.masterArray[(x+i)];
                if (currentValue == undefined){
                    return
                } else {      
                    $scope.data.push(currentValue);
                }
            };
        }
        //This function is called when filtering, so that new data is added into an empty array
        function resetArray(){
            $scope.masterArray = [];
            $scope.originalArray = [];
            $scope.data =[];
            console.log($scope.masterArray);
        };

        //using a factory gets a list of all pirate parties using $http.get and it transforms the object into array of objects

        pictureAndPostFactory.partyList().then(function(successResponse){
            $scope.partyList = Object.values(successResponse)
        });
        
        $scope.emptyInput = function(){
            $scope.partySelection = "";
        }

        //function that sorts entries by reach in ascending order
        //currently not available
        /*$scope.sortAscViews = function(){ 
            $scope.masterArray.sort(function(a, b){
            return a.post_likes > b.post_likes;
        });
        $scope.data = [];
        loadData();
        };*/

        //function that sorts entries by reach in descending order
        $scope.sortDescViews = function(){
            resetArray()
            $("#asc-desc-views").addClass("reach-selected");
            $scope.address.sort = "likes";
            $scope.address.offset = 0;
            $scope.address;
            $scope.loadMore();
        };

        $scope.defaultSort = function(){
            resetArray()
            $scope.address = {
                socialPlatform: "",
                sort: "",
                partyCode: "",
                offset: 0
            };
            $scope.loadMore(); 
            console.log("default")
            $(".up").removeClass("arrow-color")
            $(".down").removeClass("arrow-color")
            $("#asc-desc-views").removeClass("reach-selected");
        }
        //function that filters parties
        $scope.filterParty = function(code) {
            resetArray()
            $scope.address.partyCode = code;
            $scope.loadMore(); 
        }

        $scope.filterPlatform = function(platform){
            if (platform == "all"){
                resetArray()
                $scope.address.socialPlatform = "";
                $scope.loadMore();
            } else {
                resetArray()
                $scope.address.socialPlatform = platform;
                $scope.loadMore();
            };      
        };
        //clicking enter after searching for party "clicks" on the first party in the list
        $("#party-selection-search").on('keydown', function (e) {
            if (e.keyCode == 13) {
                $(this).parent().next().click();
            };
        });

        $("#party-selection-button").click(function(){
            $("#party-selection-search").click().focus();
        });


        //function that toggles between ascending and descending amount of reach
        //the API currently doesn't support descending/ascending sort,so this code is pointless
        //If it is added, uncomment and change the function called by the element with ID asc-desc-views
        /*$scope.sortByViews = function(){
            if ( $("#asc-desc-views").hasClass("desc") ) {
                $("#asc-desc-views").removeClass("desc")
                $("#asc-desc-views").addClass("asc")
                $scope.sortAscViews()       
            }*/
            //same reason as above
           /* else {
                $("#asc-desc-views").addClass("toggled")
                $("#asc-desc-views").addClass("desc")
                $("#asc-desc-views").removeClass("asc")
                $scope.sortDescViews();
            };
        }*/

        //stops dropdown from closing
        $('#platform-selection').bind('click', function (e) { e.stopPropagation() })
        $('#party-selection-search').bind('click', function (e) { e.stopPropagation() })

    }]);
})(jQuery); // end of jQuery name space